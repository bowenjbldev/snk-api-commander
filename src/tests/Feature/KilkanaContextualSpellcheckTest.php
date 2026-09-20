<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaContextualSpellcheckController;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * El servidor no se fía ni del panel ni del proveedor: acota lo que entra, comprueba lo que
 * vuelve y no deja rastro del texto en ningún sitio.
 */
class KilkanaContextualSpellcheckTest extends TestCase
{
    private const PARAGRAPH = 'La [[1:desición]] responde a la [[2:polemica]] de Adif.';

    protected function setUp(): void
    {
        parent::setUp();

        DB::setDefaultConnection('tenant');
        foreach (['Core', 'Kilkana'] as $package) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$package}/database/migrations",
            ])->run();
        }
        app(SnkKilkanaRoleCatalogService::class)->sync();
        config(['kilkana.kilkana.spellcheck.key' => 'test-key', 'cache.default' => 'array']);
        Cache::flush();

        $writer = CoreUserModel::create(['name' => 'Iván', 'email' => 'ivan@eldiario.example', 'password' => 'secret1234']);
        $writer->assignRole('reporter');
        $this->actingAs($writer, 'sanctum');
    }

    public function test_it_is_off_by_default_and_does_not_call_anyone(): void
    {
        Http::fake();

        $response = $this->choose($this->body());

        $this->assertSame(409, $response->getStatusCode());
        Http::assertNothingSent();
    }

    public function test_it_returns_one_decision_per_typo_and_discards_a_choice_it_never_offered(): void
    {
        $this->enable();
        Http::fake([
            'api.typesafe.ai/*' => Http::response([
                'model' => 'jev-1.13.0',
                'answers' => [
                    't1' => ['type' => 'choice', 'choice' => 'decisión', 'confidence' => 0.97, 'probabilities' => []],
                    // Una elección fuera de lo enviado: el contenido del párrafo pudo desviar al modelo.
                    't2' => ['type' => 'choice', 'choice' => 'polémico', 'confidence' => 0.9, 'probabilities' => []],
                ],
                'usage' => ['input_tokens' => 512, 'output_tokens' => 0],
            ]),
        ]);

        $payload = $this->choose($this->body())->getData(true);

        $this->assertSame([
            ['paragraph' => 3, 'mark' => 1, 'choice' => 'decisión', 'keep' => false, 'confidence' => 0.97],
        ], $payload['choices'], 'La segunda errata no trae decisión: lo elegido no estaba entre los candidatos.');

        Http::assertSent(function (ClientRequest $request) {
            $sent = $request->data();

            return $request->hasHeader('Authorization', 'Bearer test-key')
                && $sent['model'] === 'jev-1.13.0'
                && $sent['state'] === ['parrafo' => self::PARAGRAPH, 'titular' => 'El pleno decide']
                && array_keys($sent['questions']) === ['t1', 't2']
                && array_keys($sent['questions']['t1']['criteria']) === ['sedición', 'decisión', 'dejar_como_esta', 'ninguna_encaja'];
        });
    }

    public function test_keep_and_none_come_back_without_a_word(): void
    {
        $this->enable();
        Http::fake(['api.typesafe.ai/*' => Http::response($this->answers(['t1' => 'dejar_como_esta', 't2' => 'ninguna_encaja']))]);

        $choices = $this->choose($this->body())->getData(true)['choices'];

        $this->assertSame([null, true], [$choices[0]['choice'], $choices[0]['keep']]);
        $this->assertSame([null, false], [$choices[1]['choice'], $choices[1]['keep']]);
    }

    public function test_the_same_paragraph_is_asked_once_and_the_cache_keeps_no_text(): void
    {
        $this->enable();
        Http::fake(['api.typesafe.ai/*' => Http::response($this->answers(['t1' => 'decisión', 't2' => 'polémica']))]);

        $first = $this->choose($this->body())->getData(true)['choices'];
        $again = $this->choose($this->body())->getData(true)['choices'];

        $this->assertSame($first, $again);
        Http::assertSentCount(1);

        // Un párrafo cambiado es otra clave: se pregunta de nuevo.
        $changed = $this->body();
        $changed['paragraphs'][0]['text'] = 'La [[1:desición]] responde a la [[2:polemica]] de Renfe.';
        $this->choose($changed);
        Http::assertSentCount(2);

        foreach (Cache::store('array')->getStore()->all() ?? [] as $key => $value) {
            $this->assertStringNotContainsString('desición', $key.json_encode($value), 'La caché guarda decisiones, no texto.');
        }
    }

    public function test_a_provider_failure_or_timeout_leaves_no_decision_and_no_error(): void
    {
        $this->enable();
        Http::fake(['api.typesafe.ai/*' => Http::response('overloaded', 529)]);

        $response = $this->choose($this->body());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $response->getData(true)['choices']);
    }

    public function test_the_log_carries_figures_and_never_the_text(): void
    {
        $this->enable();
        Http::fake(['api.typesafe.ai/*' => Http::response($this->answers(['t1' => 'decisión', 't2' => 'polémica']))]);
        Log::spy();

        $this->choose($this->body());

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) {
            return $message === 'kilkana.spellcheck.contextual'
                && $context['asked'] === 1
                && $context['typos'] === 2
                && $context['input_tokens'] === 512
                && ! str_contains(json_encode($context), 'desición');
        })->once();
    }

    public function test_what_does_not_fit_a_newsroom_paragraph_is_rejected(): void
    {
        $this->enable();
        Http::fake();

        $oversized = $this->body();
        $oversized['paragraphs'][0]['text'] = str_repeat('a', 2001);
        $this->assertRejected($oversized, 'Un párrafo de más de dos mil caracteres.');

        $tooMany = $this->body();
        $tooMany['paragraphs'][0]['typos'][0]['candidates'] = array_fill(0, 7, 'x');
        $this->assertRejected($tooMany, 'Siete candidatos.');

        $unmarked = $this->body();
        $unmarked['paragraphs'][0]['typos'][0]['word'] = 'otra';
        $this->assertSame(422, $this->choose($unmarked)->getStatusCode(), 'La marca no está en el párrafo.');

        Http::assertNothingSent();
    }

    private function enable(): void
    {
        SnkKilkanaSettingModel::firstOrCreate(['id' => 1])->forceFill(['contextual_spellcheck' => true])->save();
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return [
            'headline' => 'El pleno decide',
            'paragraphs' => [[
                'id' => 3,
                'text' => self::PARAGRAPH,
                'typos' => [
                    ['mark' => 1, 'word' => 'desición', 'candidates' => ['sedición', 'decisión']],
                    ['mark' => 2, 'word' => 'polemica', 'candidates' => ['polémica', 'polemice']],
                ],
            ]],
        ];
    }

    /**
     * @param  array<string, string>  $choices
     * @return array<string, mixed>
     */
    private function answers(array $choices): array
    {
        $answers = [];
        foreach ($choices as $id => $choice) {
            $answers[$id] = ['type' => 'choice', 'choice' => $choice, 'confidence' => 0.9, 'probabilities' => []];
        }

        return ['model' => 'jev-1.13.0', 'answers' => $answers, 'usage' => ['input_tokens' => 512, 'output_tokens' => 0]];
    }

    /** @param  array<string, mixed>  $body */
    private function choose(array $body): JsonResponse
    {
        return app(SnkKilkanaContextualSpellcheckController::class)->choose(Request::create('/', 'POST', $body));
    }

    /** @param  array<string, mixed>  $body */
    private function assertRejected(array $body, string $why): void
    {
        try {
            $this->choose($body);
            $this->fail($why);
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
