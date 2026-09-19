<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaNewsletterController;
use Packages\Kilkana\Jobs\SnkKilkanaNewsletterSendJob;
use Packages\Kilkana\Mail\SnkKilkanaNewsletterMail;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaNewsletterEventModel;
use Packages\Kilkana\Models\SnkKilkanaNewsletterIssueModel;
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
        foreach (['Core', 'Kilkana'] as $package) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$package}/database/migrations",
            ])->run();
        }

        app(SnkKilkanaRoleCatalogService::class)->sync();
        SnkKilkanaSettingModel::create(['id' => 1, 'tenant_name' => 'El Diario', 'tenant_url' => 'https://demo.kilkana.test']);
    }

    /** El asunto con el que nace un número lo pone el panel; sin él, el de la demo. */
    public function test_a_new_issue_takes_the_subject_the_panel_sends(): void
    {
        $this->asModerator();

        $sent = $this->controller()->store(Request::create('/', 'POST', ['subject' => 'Untitled issue']));
        $this->assertSame(201, $sent->getStatusCode());
        $this->assertSame('Untitled issue', $sent->getData(true)['subject']);

        $bare = $this->controller()->store(Request::create('/', 'POST'))->getData(true);
        $this->assertSame('Número sin título', $bare['subject'], 'Sin asunto, nace con el de la demo.');
    }

    public function test_sending_locks_the_issue_and_queues_batches_tagged_with_the_client(): void
    {
        Queue::fake();
        $this->asModerator();
        $this->posts(6);
        for ($i = 0; $i < 120; $i++) {
            SnkKilkanaSubscriberModel::create(['email' => "s{$i}@lectores.example", 'status' => $i % 6 === 0 ? 'pending' : 'confirmed', 'consent_source' => 'public_form']);
        }
        $issue = app(SnkKilkanaNewsletterService::class)->create(null);
        $this->controller()->update(Request::create('/', 'PUT', ['subject' => 'Domingo', 'preheader' => 'Cinco minutos']), $issue->id);

        $sent = $this->controller()->send(Request::create('https://demo.commander.test/api/v1/kilkana/newsletter/1/send', 'POST'), $issue->id)->getData(true);

        $this->assertSame(100, $sent['recipients'], 'Solo los confirmados.');
        $issue->refresh();
        $this->assertTrue($issue->sent());
        $this->assertCount(4, $issue->post_ids, 'Las cuatro últimas, fijadas.');
        Queue::assertPushed(SnkKilkanaNewsletterSendJob::class, 2);
        Queue::assertPushed(SnkKilkanaNewsletterSendJob::class, fn (SnkKilkanaNewsletterSendJob $job) => $job->client === 'demo' && count($job->subscriberIds) === 50 && $job->issueId === $issue->id);

        $this->assertSame(409, $this->controller()->update(Request::create('/', 'PUT', ['subject' => 'Otro']), $issue->id)->getStatusCode(), 'Enviado no se reescribe.');
        $this->assertSame(409, $this->controller()->send(Request::create('https://demo.commander.test/', 'POST'), $issue->id)->getStatusCode(), 'Y no se manda dos veces.');
    }

    public function test_a_batch_sends_to_still_confirmed_subscribers_with_signed_links(): void
    {
        Mail::fake();
        $this->posts(4);
        $ana = SnkKilkanaSubscriberModel::create(['email' => 'ana@lectores.example', 'status' => 'confirmed', 'consent_source' => 'public_form']);
        $unsubscribed = SnkKilkanaSubscriberModel::create(['email' => 'baja@lectores.example', 'status' => 'unsubscribed', 'consent_source' => 'public_form']);
        $service = app(SnkKilkanaNewsletterService::class);
        $issue = $service->create(null);
        $issue->forceFill(['subject' => 'Domingo'])->save();
        Queue::fake();
        $service->send($issue, 'demo_kilkana', 'demo');

        $delivered = $service->deliver($issue->id, 'demo', [$ana->id, $unsubscribed->id]);

        $this->assertSame(1, $delivered, 'La baja entre el envío y la tanda no recibe nada.');
        $this->assertSame(0, $service->deliver($issue->id, 'demo', [$ana->id, $unsubscribed->id]), 'Una tanda reintentada no vuelve a mandar a quien ya recibió.');
        Mail::assertSentCount(1);
        Mail::assertSent(SnkKilkanaNewsletterMail::class, function (SnkKilkanaNewsletterMail $email) use ($issue, $ana) {
            return $email->hasTo('ana@lectores.example')
                && $email->issueSubject === 'Domingo'
                && count($email->posts) === 4
                && str_contains($email->posts[0]['url'], "/public/newsletter/{$issue->id}/click/{$ana->id}/")
                && str_contains($email->posts[0]['url'], 'signature=')
                && str_contains((string) $email->openUrl, "/public/newsletter/{$issue->id}/open/{$ana->id}")
                && str_contains($email->unsubscribeUrl, "issue={$issue->id}");
        });
    }

    public function test_opens_and_clicks_count_once_per_subscriber_and_the_test_send_is_recorded(): void
    {
        Mail::fake();
        $this->asModerator();
        $this->posts(4);
        $ana = SnkKilkanaSubscriberModel::create(['email' => 'ana@lectores.example', 'status' => 'confirmed', 'consent_source' => 'public_form']);
        $service = app(SnkKilkanaNewsletterService::class);
        $issue = $service->create(null);
        $post = (int) SnkKilkanaPostModel::query()->first()->id;

        SnkKilkanaNewsletterEventModel::record($issue->id, $ana->id, 'open');
        SnkKilkanaNewsletterEventModel::record($issue->id, $ana->id, 'open');
        SnkKilkanaNewsletterEventModel::record($issue->id, $ana->id, 'click', $post);
        SnkKilkanaNewsletterEventModel::record($issue->id, $ana->id, 'click', $post);
        $this->assertSame(['opens' => 1, 'clicks' => 1, 'unsubs' => 0], $service->counts($issue));

        $testSend = $this->controller()->test(Request::create('https://demo.commander.test/', 'POST', ['to' => 'marta@eldiario.example']), $issue->id)->getData(true);
        $this->assertSame('marta@eldiario.example', $testSend['test_sent_to']);
        // Y llega entero con lo que trae la lista blanca de columnas: titular, entradilla y la
        // ruta, que se compone de `url_date` y `slug`.
        Mail::assertSent(SnkKilkanaNewsletterMail::class, fn (SnkKilkanaNewsletterMail $c) => $c->hasTo('marta@eldiario.example') && $c->openUrl === null
            && $c->posts[0] === ['title' => 'Pieza 0', 'excerpt' => 'Entradilla.', 'url' => 'https://demo.kilkana.test/2026/07/01/pieza-0']);

        $listing = $this->controller()->index()->getData(true);
        $this->assertSame(1, $listing['list']['confirmed']);
        $this->assertSame(['drafts' => 1], $this->controller(['counts' => '1'])->index()->getData(true));
    }

    /**
     * Pintar seis números cuesta lo mismo que pintar dos.
     *
     * Las piezas de todos los números se cargan de una vez. Por fila serían seis consultas —una
     * por las cifras y cinco por las piezas, porque el modelo carga cuatro relaciones él solo—,
     * y el listado no tiene cota de números: la pantalla se haría más cara con cada boletín.
     */
    public function test_the_newsletter_list_costs_the_same_with_two_issues_as_with_six(): void
    {
        $this->asModerator();
        $this->posts(6);
        $ids = SnkKilkanaPostModel::query()->published()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->seedTwoIssues($ids);
        // El primer paso hidrata los permisos —dos consultas que no son del listado— y las
        // deja cacheadas para el resto del proceso: sin esto la primera medida sale más cara.
        $this->controller()->index();
        $withTwo = $this->listQueries();

        $this->seedTwoIssues($ids);
        $this->seedTwoIssues($ids);
        $withSix = $this->listQueries();

        $this->assertSame($withTwo, $withSix, "Seis números costaron {$withSix} consultas y dos, {$withTwo}.");
    }

    /** Uno enviado y un borrador con piezas elegidas: las dos formas que resuelve el listado. */
    private function seedTwoIssues(array $ids): void
    {
        $service = app(SnkKilkanaNewsletterService::class);
        $service->create(null)->forceFill([
            'status' => SnkKilkanaNewsletterIssueModel::SENT,
            'sent_at' => now(),
            'post_ids' => [$ids[0], $ids[1]],
            'recipients_count' => 3,
        ])->save();
        $service->create(null)->forceFill(['post_ids' => [$ids[2]]])->save();
    }

    private function listQueries(): int
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $this->controller()->index();
        $queries = count($connection->getQueryLog());
        $connection->disableQueryLog();

        return $queries;
    }

    private function controller(array $query = []): SnkKilkanaNewsletterController
    {
        request()->query->replace($query);

        return app(SnkKilkanaNewsletterController::class);
    }

    private function asModerator(): CoreUserModel
    {
        $elena = CoreUserModel::create(['name' => 'Elena', 'email' => 'elena@eldiario.example', 'password' => 'secret1234']);
        $elena->assignRole('moderator');
        $this->actingAs($elena, 'sanctum');

        return $elena;
    }

    private function posts(int $n): void
    {
        $section = SnkKilkanaCategoryModel::firstOrCreate(['slug' => 'local'], ['name' => 'Local', 'status' => 'live', 'position' => 0]);
        $byline = SnkKilkanaAuthorModel::firstOrCreate(['slug' => 'redaccion'], ['alias' => 'Redacción']);
        for ($i = 0; $i < $n; $i++) {
            SnkKilkanaPostModel::create([
                'title' => "Pieza {$i}", 'slug' => "pieza-{$i}", 'url_date' => '2026-07-01', 'category_id' => $section->id, 'author_id' => $byline->id,
                'status' => 'published', 'publish_at' => now()->subHours($i), 'excerpt' => 'Entradilla.',
            ]);
        }
    }

    /** Un número con piezas elegidas y hora sale solo cuando llega, con esas piezas y en ese orden. */
    public function test_a_scheduled_issue_with_picked_posts_goes_out_only_when_due(): void
    {
        Queue::fake();
        $this->asModerator();
        $this->posts(6);
        $ids = SnkKilkanaPostModel::query()->published()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id);
        $issue = app(SnkKilkanaNewsletterService::class)->create(null);
        $ctrl = $this->controller();

        try {
            $ctrl->update(Request::create('/', 'PUT', ['post_ids' => [$ids[4], $ids[1]], 'scheduled_at' => now()->subMinute()->toIso8601String()]), $issue->id);
            $this->fail('Una hora pasada no es programar.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('scheduled_at', $e->errors());
        }

        $ok = $ctrl->update(Request::create('/', 'PUT', ['post_ids' => [$ids[4], $ids[1]], 'scheduled_at' => now()->addHour()->toIso8601String()]), $issue->id)->getData(true);
        $this->assertSame([(string) $ids[4], (string) $ids[1]], array_column($ok['pieces'], 'id'), 'Las elegidas, en su orden.');
        $this->assertNotNull($ok['scheduled_at']);

        $service = app(SnkKilkanaNewsletterService::class);
        $this->assertSame(0, $service->sendDue('demo_kilkana', 'demo'), 'Todavía no es la hora.');
        $this->travel(2)->hours();
        $this->assertSame(1, $service->sendDue('demo_kilkana', 'demo'));
        $this->assertSame(0, $service->sendDue('demo_kilkana', 'demo'), 'Y no sale dos veces.');
        $this->assertTrue($issue->fresh()->sent());
        $this->assertSame([$ids[4], $ids[1]], $issue->fresh()->post_ids);
    }
}
