<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Enums\SnkKilkanaContentTierEnum;
use Packages\Kilkana\Http\Controllers\SnkKilkanaAuthController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaCommentsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaReadersController;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaReaderReadModel;
use Packages\Kilkana\Models\SnkKilkanaSubscriberModel;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * Un lector es una cuenta, no un perfil: se lista, se cruza con el boletín y con lo que ha
 * escrito y leído, y se bloquea. Lo que se fija aquí es que la redacción no sale en esa lista,
 * que las pestañas son filtros y no estados, que bloquear cierra y se deshace, y que una
 * lectura medida solo se apunta a quien viene con su cuenta y solo sobre una pieza con muro.
 */
class KilkanaReadersTest extends TestCase
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
    }

    public function test_the_list_only_shows_readers_and_each_row_includes_newsletter_comments_and_reads(): void
    {
        $this->asModerator();
        $amparo = $this->reader('amparo@lectores.example', 'Amparo Ruiz');
        $joaquin = $this->reader('joaquin@lectores.example', 'Joaquín Vidal');
        $post = $this->makePost('Plan de aparcamientos', SnkKilkanaContentTierEnum::Metered);
        $this->comment($amparo, $post, 'Las plazas ya estaban saturadas.', 'approved');
        $this->comment($amparo, $post, 'Insulto que no sale.', 'rejected');
        SnkKilkanaSubscriberModel::create(['email' => 'amparo@lectores.example', 'status' => 'confirmed', 'consent_source' => 'public_form']);
        SnkKilkanaReaderReadModel::record($amparo->id, $post);

        $listing = $this->readers()->index()->getData(true);

        $this->assertSame(2, $listing['meta']['total'], 'La moderadora no es una lectora.');
        $row = collect($listing['data'])->firstWhere('id', (string) $amparo->id);
        $this->assertSame('Amparo Ruiz', $row['name']);
        $this->assertSame(['comments' => 2, 'rejected' => 1, 'reads' => 1, 'blocked' => false], array_intersect_key($row, ['comments' => 1, 'rejected' => 1, 'reads' => 1, 'blocked' => 1]));
        $this->assertSame('confirmed', $row['newsletter']['status']);
        $this->assertSame('public_form', $row['newsletter']['consent_source']);
        $this->assertNotNull($row['last_seen_at']);
        $this->assertNull(collect($listing['data'])->firstWhere('id', (string) $joaquin->id)['newsletter']);
        $this->assertSame(['all' => 2, 'subscribers' => 1, 'commenters' => 1, 'blocked' => 0], $listing['counts']);
        $this->assertSame(['accounts' => 2, 'confirmed_subscribers' => 1, 'commented_this_month' => 1, 'blocked' => 0], $listing['stats']);
    }

    /** Las pestañas son tres preguntas sobre la misma persona, y un bloqueado no es un comentarista. */
    public function test_tabs_filter_and_search_matches_name_and_email(): void
    {
        $this->asModerator();
        $amparo = $this->reader('amparo@lectores.example', 'Amparo Ruiz');
        $ramon = $this->reader('rsempere@lectores.example', 'Ramón Sempere');
        $post = $this->makePost('Plan de aparcamientos');
        $this->comment($amparo, $post, 'Las plazas ya estaban saturadas.', 'approved');
        $this->comment($ramon, $post, 'Publicidad.', 'spam');
        $this->comment($this->reader('u9902@correo-gratis.example', 'u9902'), $post, 'Compra aqui.', 'spam');
        $ramon->forceFill(['suspended_at' => now()])->save();

        $commenters = $this->readers(['tab' => 'commenters'])->index()->getData(true);
        $this->assertSame([(string) $amparo->id], array_column($commenters['data'], 'id'), 'Solo spam no es comentar.');

        $blocked = $this->readers(['tab' => 'blocked'])->index()->getData(true);
        $this->assertSame([(string) $ramon->id], array_column($blocked['data'], 'id'));
        $this->assertTrue($blocked['data'][0]['blocked']);

        $byEmail = $this->readers(['q' => 'rsempere'])->index()->getData(true);
        $this->assertSame([(string) $ramon->id], array_column($byEmail['data'], 'id'));

        $countsOnly = $this->readers(['counts' => '1'])->index()->getData(true);
        $this->assertSame(['accounts' => 3], $countsOnly);
    }

    public function test_blocking_closes_the_account_and_empties_its_queue_and_unblocking_reopens_it(): void
    {
        $this->asModerator();
        $ramon = $this->reader('rsempere@lectores.example', 'Ramón Sempere');
        $ramon->createToken('calle');
        $post = $this->makePost('Plan de aparcamientos');
        $this->comment($ramon, $post, 'Pendiente.', 'pending');
        $this->comment($ramon, $post, 'Ya aprobado.', 'approved');

        $block = $this->readers()->block($ramon->id);
        $this->assertSame(200, $block->getStatusCode());
        $this->assertSame(1, $block->getData(true)['rejected']);
        $ramon->refresh();
        $this->assertNotNull($ramon->suspended_at);
        $this->assertSame(0, $ramon->tokens()->count());
        $this->assertSame(1, SnkKilkanaCommentModel::query()->where('user_id', $ramon->id)->where('status', 'rejected')->count());
        $this->assertSame(1, SnkKilkanaCommentModel::query()->where('user_id', $ramon->id)->where('status', 'approved')->count(), 'Lo aprobado se queda.');

        $this->assertSame(200, $this->readers()->unblock($ramon->id)->getStatusCode());
        $this->assertNull($ramon->refresh()->suspended_at);
        $this->assertSame(1, SnkKilkanaCommentModel::query()->where('user_id', $ramon->id)->where('status', 'rejected')->count(), 'Desbloquear no deshace una decisión.');
    }

    /** A un compañero no se le bloquea desde aquí: la lista no lo ve ni con su id a mano. */
    public function test_newsroom_accounts_cannot_be_blocked_from_readers(): void
    {
        $elena = $this->asModerator();

        $this->assertSame(404, $this->readers()->block($elena->id)->getStatusCode());
        $this->assertNull($elena->refresh()->suspended_at);
    }

    /** Sin `update readers` no se bloquea, ni desde aquí ni desde la moderación. */
    public function test_blocking_belongs_to_whoever_manages_readers(): void
    {
        $editor = CoreUserModel::create(['name' => 'Iván', 'email' => 'ivan@eldiario.example', 'password' => 'secret1234']);
        $editor->assignRole('editor');
        $this->actingAs($editor, 'sanctum');
        $ramon = $this->reader('rsempere@lectores.example', 'Ramón Sempere');

        $this->assertSame(403, $this->readers()->index()->getStatusCode());
        $this->assertSame(403, $this->readers()->block($ramon->id)->getStatusCode());
    }

    /**
     * Una lectura medida se apunta una vez por mes y solo sobre una pieza con muro. Quién la
     * apunta lo decide `recordView` con el token que viaje; eso no se prueba aquí porque el
     * contador diario va en SQL de MariaDB y SQLite no lo ejecuta.
     */
    public function test_a_metered_read_counts_once_a_month_and_a_free_one_does_not_count(): void
    {
        $amparo = $this->reader('amparo@lectores.example', 'Amparo Ruiz');
        $metered = $this->makePost('Plan de aparcamientos', SnkKilkanaContentTierEnum::Metered);
        $free = $this->makePost('Fiestas de agosto', SnkKilkanaContentTierEnum::Free);

        SnkKilkanaReaderReadModel::record($amparo->id, $metered);
        SnkKilkanaReaderReadModel::record($amparo->id, $metered);
        SnkKilkanaReaderReadModel::record($amparo->id, $free);
        SnkKilkanaReaderReadModel::record($amparo->id, $metered, now()->subMonth());

        $this->assertSame(2, SnkKilkanaReaderReadModel::query()->where('user_id', $amparo->id)->count());
        $this->assertSame(1, SnkKilkanaReaderReadModel::query()->inMonth()->count(), 'Releer no gasta otra; el mes pasado no es este.');
        $this->assertSame(0, SnkKilkanaReaderReadModel::query()->where('post_id', $free->id)->count(), 'Una gratuita no consume.');
    }

    private function asModerator(): CoreUserModel
    {
        $elena = CoreUserModel::create(['name' => 'Elena', 'email' => 'elena@eldiario.example', 'password' => 'secret1234']);
        $elena->assignRole('moderador');
        $this->actingAs($elena, 'sanctum');

        return $elena;
    }

    /**
     * La banda no vuelve a contar lo que las pestañas ya contaron.
     *
     * `stats['accounts']` es la misma consulta que `counts['all']`, y `stats['blocked']` la
     * misma que `counts['blocked']`. La pantalla pide las dos cosas a la vez, así que contarlas
     * aparte serían dos consultas de más en cada carga, en cada página y en cada tecla del buscador.
     */
    public function test_the_stats_band_does_not_recount_what_the_tabs_already_counted(): void
    {
        $this->asModerator();
        $amparo = $this->reader('amparo@lectores.example', 'Amparo Ruiz');
        $this->reader('joaquin@lectores.example', 'Joaquín Vidal');
        $this->comment($amparo, $this->makePost('Plan'), 'Cabe.', 'approved');

        // La primera pasada hidrata los permisos y deja la caché caliente: sin esto, la medida
        // que salga primero carga con consultas que no son del listado.
        $this->readers(['summary' => 'counts,stats'])->index();

        $withoutSummary = $this->listQueries(['summary' => '']);
        $counts = $this->listQueries(['summary' => 'counts']);
        $band = $this->listQueries(['summary' => 'stats']);
        $together = $this->listQueries(['summary' => 'counts,stats']);

        $this->assertSame(4, $counts - $withoutSummary, 'Una consulta por pestaña.');
        $this->assertSame(4, $band - $withoutSummary, 'Y cuatro cifras en la banda.');
        $this->assertSame(6, $together - $withoutSummary, 'Pero juntas, seis: dos de las ocho son la misma consulta.');
        $this->assertArrayNotHasKey('counts', $this->readers(['summary' => ''])->index()->getData(true), 'Y sin pedirlas no llegan.');
    }

    private function listQueries(array $query): int
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $this->readers($query)->index();
        $n = count($connection->getQueryLog());
        $connection->disableQueryLog();

        return $n;
    }

    /**
     * Con los agregados pedidos por defecto, que es lo que hace la pantalla: la API no los
     * manda si no se piden. Quien quiera probar que no llegan, pasa `summary` vacío.
     */
    private function readers(array $query = []): SnkKilkanaReadersController
    {
        request()->query->replace(['summary' => 'counts,stats', ...$query]);

        return app(SnkKilkanaReadersController::class);
    }

    private function reader(string $email, string $name): CoreUserModel
    {
        $reader = CoreUserModel::create(['name' => $name, 'email' => $email, 'password' => 'secret1234']);
        $reader->assignRole('reader');

        return $reader;
    }

    private function comment(CoreUserModel $author, SnkKilkanaPostModel $post, string $body, string $status): SnkKilkanaCommentModel
    {
        return SnkKilkanaCommentModel::create([
            'post_id' => $post->id,
            'user_id' => $author->id,
            'body' => $body,
            'status' => $status,
        ]);
    }

    private function makePost(string $title, SnkKilkanaContentTierEnum $tier = SnkKilkanaContentTierEnum::Free): SnkKilkanaPostModel
    {
        $section = SnkKilkanaCategoryModel::firstOrCreate(['slug' => 'local'], ['name' => 'Local', 'status' => 'live', 'position' => 0]);
        $byline = SnkKilkanaAuthorModel::firstOrCreate(['slug' => 'redaccion'], ['alias' => 'Redacción']);

        return SnkKilkanaPostModel::create([
            'title' => $title,
            'slug' => str($title)->slug()->value(),
            'url_date' => '2026-07-01',
            'category_id' => $section->id,
            'author_id' => $byline->id,
            'status' => 'published',
            'publish_at' => '2026-07-01 08:00:00',
            'content_tier' => $tier,
        ]);
    }

    /** Un lector nace solo desde la calle, y lo único que puede hacer con su sesión es comentar. */
    public function test_a_reader_can_self_register_and_their_comment_starts_pending(): void
    {
        $auth = new SnkKilkanaAuthController;
        $creation = fn (array $extra = []) => $auth->register(Request::create('/', 'POST', $extra + [
            'name' => 'Vecina Nueva', 'email' => 'vecina@lectores.example', 'password' => 'correcthorse1', 'password_confirmation' => 'correcthorse1',
        ]));

        $response = $creation();
        $this->assertSame(201, $response->getStatusCode());
        $this->assertNotEmpty($response->getData(true)['token']);
        $neighbour = CoreUserModel::query()->where('email', 'vecina@lectores.example')->first();
        $this->assertTrue($neighbour->hasRole('reader'));
        $this->assertSame(['create comments'], $neighbour->getAllPermissions()->pluck('name')->all(), 'Comentar y nada más.');

        try {
            $creation();
            $this->fail('El mismo correo no se registra dos veces.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        $post = $this->makePost('Plan');
        $this->actingAs($neighbour, 'sanctum');
        $comment = app(SnkKilkanaCommentsController::class)->store(Request::create('/', 'POST', ['post_id' => $post->id, 'body' => 'Hola']));
        $this->assertSame(201, $comment->getStatusCode());
        $this->assertSame('pending', SnkKilkanaCommentModel::query()->where('user_id', $neighbour->id)->first()->status->value);

        $this->asModerator();
        $this->assertContains('vecina@lectores.example', array_column($this->readers()->index()->getData(true)['data'], 'email'), 'Y aparece en Lectores.');
    }

    /** Retirar a un lector: lo que identifica se va, la conversación se queda sin nombre, y el boletín lo olvida. */
    public function test_removing_a_reader_erases_identifying_data_and_leaves_their_comments_anonymous(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
        $neighbour = $this->reader('vecina@lectores.example', 'Vecina Curiosa');
        $post = $this->makePost('Plan');
        $this->comment($neighbour, $post, 'Hola', 'approved');
        $this->comment($neighbour, $post, 'Pendiente', 'pending');
        SnkKilkanaSubscriberModel::create(['email' => 'vecina@lectores.example', 'status' => 'confirmed', 'consent_source' => 'public_form']);

        $this->assertSame(200, $this->readers()->anonymize($neighbour->id)->getStatusCode());

        $retired = $neighbour->fresh();
        $this->assertSame('Lector', $retired->name);
        $this->assertNull($retired->lastname);
        $this->assertStringStartsWith('retired-', $retired->email);
        $this->assertNotNull($retired->suspended_at);
        $this->assertSame(2, SnkKilkanaCommentModel::query()->where('user_id', $neighbour->id)->count(), 'Los comentarios se quedan.');
        $this->assertSame('rejected', SnkKilkanaCommentModel::query()->where('body', 'Pendiente')->first()->status->value);
        $this->assertSame(0, SnkKilkanaSubscriberModel::query()->where('email', 'vecina@lectores.example')->count());
        $this->assertSame(404, $this->readers()->anonymize(999)->getStatusCode());
    }
}
