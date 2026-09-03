<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaNewsletterController;
use Packages\Kilkana\Jobs\SnkKilkanaNewsletterSendJob;
use Packages\Kilkana\Mail\SnkKilkanaNewsletterMail;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaNewsletterEventModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Models\SnkKilkanaSubscriberModel;
use Packages\Kilkana\Services\SnkKilkanaNewsletterService;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * Enviar es irreversible, y de ahí sale todo lo que se fija aquí: un número se fija al
 * enviarlo y no se reescribe, el envío va por tandas a la cola con lo que hace falta para
 * volver al periódico, una tanda manda solo a quien sigue confirmado y con enlaces contados,
 * y lo que la lista hace con un número cuenta una vez por suscriptor.
 */
class KilkanaNewsletterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::setDefaultConnection('tenant');
        foreach (['Core', 'Kilkana'] as $paquete) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$paquete}/database/migrations",
            ])->run();
        }

        app(SnkKilkanaRoleCatalogService::class)->sync();
        config(['core.tenant_base_domain' => 'kilkana.test']);
        SnkKilkanaSettingModel::create(['id' => 1, 'tenant_name' => 'El Diario', 'tenant_url' => 'https://demo.kilkana.test']);
    }

    public function test_enviar_fija_el_numero_y_encola_las_tandas_con_su_periodico(): void
    {
        Queue::fake();
        $this->comoModeradora();
        $this->piezas(6);
        for ($i = 0; $i < 120; $i++) {
            SnkKilkanaSubscriberModel::create(['email' => "s{$i}@lectores.example", 'status' => $i % 6 === 0 ? 'pending' : 'confirmed', 'consent_source' => 'public_form']);
        }
        $issue = app(SnkKilkanaNewsletterService::class)->create(null);
        $this->controlador()->update(Request::create('/', 'PUT', ['subject' => 'Domingo', 'preheader' => 'Cinco minutos']), $issue->id);

        $envio = $this->controlador()->send(Request::create('https://demo.kilkana.test/api/v1/kilkana/newsletter/1/send', 'POST'), $issue->id)->getData(true);

        $this->assertSame(100, $envio['recipients'], 'Solo los confirmados.');
        $issue->refresh();
        $this->assertTrue($issue->sent());
        $this->assertCount(4, $issue->post_ids, 'Las cuatro últimas, fijadas.');
        Queue::assertPushed(SnkKilkanaNewsletterSendJob::class, 2);
        Queue::assertPushed(SnkKilkanaNewsletterSendJob::class, fn (SnkKilkanaNewsletterSendJob $job) => $job->client === 'demo' && count($job->subscriberIds) === 50 && $job->issueId === $issue->id);

        $this->assertSame(409, $this->controlador()->update(Request::create('/', 'PUT', ['subject' => 'Otro']), $issue->id)->getStatusCode(), 'Enviado no se reescribe.');
        $this->assertSame(409, $this->controlador()->send(Request::create('https://demo.kilkana.test/', 'POST'), $issue->id)->getStatusCode(), 'Y no se manda dos veces.');
    }

    public function test_una_tanda_manda_a_quien_sigue_confirmado_con_enlaces_firmados(): void
    {
        Mail::fake();
        $this->piezas(4);
        $ana = SnkKilkanaSubscriberModel::create(['email' => 'ana@lectores.example', 'status' => 'confirmed', 'consent_source' => 'public_form']);
        $baja = SnkKilkanaSubscriberModel::create(['email' => 'baja@lectores.example', 'status' => 'unsubscribed', 'consent_source' => 'public_form']);
        $servicio = app(SnkKilkanaNewsletterService::class);
        $issue = $servicio->create(null);
        $issue->forceFill(['subject' => 'Domingo'])->save();
        Queue::fake();
        $servicio->send($issue, 'demo_kilkana', 'demo');

        $enviados = $servicio->deliver($issue->id, 'demo', [$ana->id, $baja->id]);

        $this->assertSame(1, $enviados, 'La baja entre el envío y la tanda no recibe nada.');
        $this->assertSame(0, $servicio->deliver($issue->id, 'demo', [$ana->id, $baja->id]), 'Una tanda reintentada no vuelve a mandar a quien ya recibió.');
        Mail::assertSentCount(1);
        Mail::assertSent(SnkKilkanaNewsletterMail::class, function (SnkKilkanaNewsletterMail $correo) use ($issue, $ana) {
            return $correo->hasTo('ana@lectores.example')
                && $correo->issueSubject === 'Domingo'
                && count($correo->pieces) === 4
                && str_contains($correo->pieces[0]['url'], "/public/newsletter/{$issue->id}/click/{$ana->id}/")
                && str_contains($correo->pieces[0]['url'], 'signature=')
                && str_contains((string) $correo->openUrl, "/public/newsletter/{$issue->id}/open/{$ana->id}")
                && str_contains($correo->unsubscribeUrl, "issue={$issue->id}");
        });
    }

    public function test_abrir_y_pulsar_cuentan_una_vez_por_suscriptor_y_la_prueba_deja_constancia(): void
    {
        Mail::fake();
        $this->comoModeradora();
        $this->piezas(4);
        $ana = SnkKilkanaSubscriberModel::create(['email' => 'ana@lectores.example', 'status' => 'confirmed', 'consent_source' => 'public_form']);
        $servicio = app(SnkKilkanaNewsletterService::class);
        $issue = $servicio->create(null);
        $pieza = (int) SnkKilkanaPostModel::query()->first()->id;

        SnkKilkanaNewsletterEventModel::record($issue->id, $ana->id, 'open');
        SnkKilkanaNewsletterEventModel::record($issue->id, $ana->id, 'open');
        SnkKilkanaNewsletterEventModel::record($issue->id, $ana->id, 'click', $pieza);
        SnkKilkanaNewsletterEventModel::record($issue->id, $ana->id, 'click', $pieza);
        $this->assertSame(['opens' => 1, 'clicks' => 1, 'unsubs' => 0], $servicio->counts($issue));

        $prueba = $this->controlador()->test(Request::create('https://demo.kilkana.test/', 'POST', ['to' => 'marta@eldiario.example']), $issue->id)->getData(true);
        $this->assertSame('marta@eldiario.example', $prueba['test_sent_to']);
        Mail::assertSent(SnkKilkanaNewsletterMail::class, fn (SnkKilkanaNewsletterMail $c) => $c->hasTo('marta@eldiario.example') && $c->openUrl === null);

        $lista = $this->controlador()->index()->getData(true);
        $this->assertSame(1, $lista['list']['confirmed']);
        $this->assertSame(['drafts' => 1], $this->controlador(['counts' => '1'])->index()->getData(true));
    }

    private function controlador(array $query = []): SnkKilkanaNewsletterController
    {
        request()->query->replace($query);

        return app(SnkKilkanaNewsletterController::class);
    }

    private function comoModeradora(): CoreUserModel
    {
        $elena = CoreUserModel::create(['name' => 'Elena', 'email' => 'elena@eldiario.example', 'password' => 'secret1234']);
        $elena->assignRole('moderador');
        $this->actingAs($elena, 'sanctum');

        return $elena;
    }

    private function piezas(int $n): void
    {
        $seccion = SnkKilkanaCategoryModel::firstOrCreate(['slug' => 'local'], ['name' => 'Local', 'status' => 'live', 'position' => 0]);
        $firma = SnkKilkanaAuthorModel::firstOrCreate(['slug' => 'redaccion'], ['alias' => 'Redacción']);
        for ($i = 0; $i < $n; $i++) {
            SnkKilkanaPostModel::create([
                'title' => "Pieza {$i}", 'slug' => "pieza-{$i}", 'url_date' => '2026-07-01', 'category_id' => $seccion->id, 'author_id' => $firma->id,
                'status' => 'published', 'publish_at' => now()->subHours($i), 'excerpt' => 'Entradilla.',
            ]);
        }
    }
}
