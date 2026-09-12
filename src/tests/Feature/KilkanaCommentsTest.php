<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Enums\SnkKilkanaCommentStatusEnum;
use Packages\Kilkana\Http\Controllers\SnkKilkanaAuthController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaCommentsController;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Services\SnkKilkanaPublicService;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * La moderación no es un archivo que se consulta: es una cola que se vacía.
 *
 * Lo que se fija aquí es lo que hace falta para decidir sin abrir nada más —la pieza, la
 * frase de arriba y el historial de la cuenta—, que rechazar no es ni spam ni borrar, y que
 * bloquear a quien escribe cierra la cuenta y vacía lo que tenía en la cola.
 */
class KilkanaCommentsTest extends TestCase
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

    public function test_the_queue_includes_the_post_the_parent_reply_and_the_account_history(): void
    {
        $this->asModerator();
        $amparo = $this->reader('amparo@lectores.example', 'Amparo Ruiz');
        $post = $this->makePost('Plan de aparcamientos');
        $first = $this->comment($amparo, $post, 'Las plazas ya estaban saturadas.', 'approved');
        $this->comment($amparo, $post, 'Insulto que no sale.', 'rejected');
        $reply = $this->comment($amparo, $post, 'Y en Navidad sera inviable.', 'pending', $first);

        $queue = $this->moderation()->index()->getData(true);

        $this->assertSame(['pending' => 1, 'approved' => 1, 'rejected' => 1, 'spam' => 0, 'all' => 3], $queue['counts']);
        $this->assertSame(3, $queue['meta']['total']);
        $this->assertSame($reply->created_at->toJSON(), $queue['oldest_pending_at'] ? Carbon::parse($queue['oldest_pending_at'])->toJSON() : null);

        $row = collect($queue['data'])->firstWhere('id', $reply->id);
        $this->assertSame('Plan de aparcamientos', $row['on']['title']);
        $this->assertSame('Amparo Ruiz', $row['reply_to']['author']);
        $this->assertSame('Las plazas ya estaban saturadas.', $row['reply_to']['body']);
        $this->assertSame(['total' => 3, 'rejected' => 1, 'blocked' => false], array_intersect_key($row['author'], ['total' => 1, 'rejected' => 1, 'blocked' => 1]));
        $this->assertSame('Amparo Ruiz', $row['author']['name']);
    }

    /** Abre en pendiente y la búsqueda mira el texto y a quien lo firma. */
    public function test_the_queue_filters_by_status_and_by_search_text(): void
    {
        $this->asModerator();
        $amparo = $this->reader('amparo@lectores.example', 'Amparo Ruiz');
        $joaquin = $this->reader('joaquin@lectores.example', 'Joaquin Vidal');
        $post = $this->makePost('Plan de aparcamientos');
        $this->comment($amparo, $post, 'Las plazas ya estaban saturadas.', 'pending');
        $this->comment($joaquin, $post, 'El horario de carga deja fuera al reparto.', 'approved');

        $pending = $this->moderation(['status' => 'pending'])->index()->getData(true);
        $this->assertCount(1, $pending['data']);
        $this->assertSame('pending', $pending['data'][0]['status']);

        $byAuthorName = $this->moderation(['q' => 'vidal'])->index()->getData(true);
        $this->assertCount(1, $byAuthorName['data']);
        $this->assertSame('Joaquin Vidal', $byAuthorName['data'][0]['author']['name']);

        $search = $this->moderation(['q' => 'saturadas'])->index()->getData(true);
        $this->assertCount(1, $search['data']);
    }

    /** Rechazar no es spam ni es borrar: el comentario se queda, no se ve, y cuenta en el historial. */
    public function test_rejecting_is_an_outcome_of_its_own(): void
    {
        $this->asModerator();
        $amparo = $this->reader('amparo@lectores.example', 'Amparo Ruiz');
        $post = $this->makePost('Plan de aparcamientos');
        $c = $this->comment($amparo, $post, 'No sale.', 'pending');

        $response = $this->moderation()->bulk(Request::create('/', 'POST', ['ids' => [$c->id], 'action' => 'reject']));

        $this->assertSame(1, $response->getData(true)['count']);
        $this->assertSame(SnkKilkanaCommentStatusEnum::Rejected, $c->fresh()->status);
        $this->assertFalse($c->fresh()->status->isPublic());
        $this->assertSame(0, SnkKilkanaCommentModel::query()->approved()->count());
    }

    public function test_blocking_the_author_closes_their_account_and_empties_their_queue(): void
    {
        $this->asModerator();
        $amparo = $this->reader('amparo@lectores.example', 'Amparo Ruiz');
        $amparo->createToken('login');
        $post = $this->makePost('Plan de aparcamientos');
        $target = $this->comment($amparo, $post, 'Insulto.', 'pending');
        $this->comment($amparo, $post, 'Otro insulto.', 'pending');
        $older = $this->comment($amparo, $post, 'Uno de antes.', 'approved');

        $response = $this->moderation()->blockAuthor($target->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $response->getData(true)['rejected']);
        $this->assertNotNull($amparo->fresh()->suspended_at);
        $this->assertSame(0, $amparo->tokens()->count(), 'Sus sesiones se cortan.');
        $this->assertSame(SnkKilkanaCommentStatusEnum::Approved, $older->fresh()->status, 'Lo ya publicado no se toca.');

        $login = (new SnkKilkanaAuthController)->login(Request::create('/', 'POST', ['email' => 'amparo@lectores.example', 'password' => 'secret1234']));
        $this->assertSame(403, $login->getStatusCode());

        $queue = $this->moderation()->index()->getData(true);
        $this->assertSame(1, $queue['blocked_readers']);
        $this->assertTrue(collect($queue['data'])->firstWhere('id', $target->id)['author']['blocked']);
    }

    /** A un compañero no se le bloquea desde la moderación, y a una cuenta dada de baja tampoco. */
    public function test_only_readers_can_be_blocked(): void
    {
        $this->asModerator();
        $staffWriter = CoreUserModel::create(['name' => 'Lucia', 'email' => 'lucia@eldiario.example', 'password' => 'secret1234']);
        $staffWriter->assignRole('redactor');
        $post = $this->makePost('Plan de aparcamientos');
        $staffComment = $this->comment($staffWriter, $post, 'Gracias por el aviso.', 'approved');
        $orphan = SnkKilkanaCommentModel::create(['post_id' => $post->id, 'user_id' => null, 'body' => 'Sin cuenta.', 'status' => 'pending']);

        $this->assertSame(409, $this->moderation()->blockAuthor($staffComment->id)->getStatusCode());
        $this->assertNull($staffWriter->fresh()->suspended_at);

        // Lector Y redactora a la vez: sigue siendo un compañero. La guarda falla cerrado.
        $staffWriter->assignRole('reader');
        $this->assertSame(409, $this->moderation()->blockAuthor($staffComment->id)->getStatusCode());
        $this->assertNull($staffWriter->fresh()->suspended_at);
        $this->assertSame(409, $this->moderation()->blockAuthor($orphan->id)->getStatusCode());
        $this->assertSame(404, $this->moderation()->blockAuthor(999)->getStatusCode());
    }

    /** El rail pregunta cuántos esperan en cada carga del armazón: solo la cifra, sin la página. */
    public function test_the_rail_can_request_only_the_counts(): void
    {
        $this->asModerator();
        $amparo = $this->reader('amparo@lectores.example', 'Amparo Ruiz');
        $post = $this->makePost('Plan de aparcamientos');
        $this->comment($amparo, $post, 'Uno.', 'pending');
        $this->comment($amparo, $post, 'Dos.', 'spam');

        $countsOnly = $this->moderation(['counts' => '1'])->index()->getData(true);

        $this->assertSame(['counts', 'oldest_pending_at'], array_keys($countsOnly));
        $this->assertSame(1, $countsOnly['counts']['pending']);
        $this->assertSame(2, $countsOnly['counts']['all']);
    }

    /** Moderar es del que modera: un redactor lee la cola y no decide. */
    public function test_read_only_accounts_cannot_block(): void
    {
        $writer = CoreUserModel::create(['name' => 'Ivan', 'email' => 'ivan@eldiario.example', 'password' => 'secret1234']);
        $writer->assignRole('redactor');
        $this->actingAs($writer, 'sanctum');

        $this->assertSame(403, app(SnkKilkanaCommentsController::class)->blockAuthor(1)->getStatusCode());
    }

    /** El panel cuenta todo lo que hay que moderar; la calle, solo lo que puede ver. */
    public function test_the_street_counts_only_approved_comments(): void
    {
        $amparo = $this->reader('amparo@lectores.example', 'Amparo Ruiz');
        $post = $this->makePost('Plan de aparcamientos');
        $this->comment($amparo, $post, 'Las plazas ya estaban saturadas.', 'approved');
        $this->comment($amparo, $post, 'Insulto que no sale.', 'rejected');
        $this->comment($amparo, $post, 'Compra aqui.', 'spam');

        $street = app(SnkKilkanaPublicService::class);
        $listed = $street->posts([])->first();
        $detail = $street->post('2026', '07', '01', $post->slug);

        // Contar los comentarios de una pieza **se pide**: un `$withCount` del modelo encendido
        // siempre añade una subconsulta a toda consulta de piezas del sistema para un valor que
        // no publica la fila del listado ni parsea el panel, y de paso fija
        // `select kilkana_posts.*`, que anula las proyecciones en silencio.
        $withCommentCount = SnkKilkanaPostModel::query()->withCount('comments')->find($post->id);
        $this->assertSame(3, $withCommentCount->comments_count, 'Total, no solo aprobados: para moderar hay que ver que hay algo.');
        $this->assertSame(1, $listed->comments_count);
        $this->assertSame(1, $detail->comments_count);
        $this->assertArrayNotHasKey('approved_comments_count', $detail->toArray());
    }

    private function asModerator(): CoreUserModel
    {
        $elena = CoreUserModel::create(['name' => 'Elena', 'email' => 'elena@eldiario.example', 'password' => 'secret1234']);
        $elena->assignRole('moderador');
        $this->actingAs($elena, 'sanctum');

        return $elena;
    }

    /**
     * Con los agregados pedidos por defecto, que es lo que hace la pantalla: la API no los
     * manda si no se piden. Quien quiera probar que no llegan, pasa `summary` vacío.
     */
    private function moderation(array $query = []): SnkKilkanaCommentsController
    {
        request()->query->replace(['summary' => 'counts,blocked_readers', ...$query]);

        return app(SnkKilkanaCommentsController::class);
    }

    private function reader(string $email, string $name): CoreUserModel
    {
        $reader = CoreUserModel::create(['name' => $name, 'email' => $email, 'password' => 'secret1234']);
        $reader->assignRole('reader');

        return $reader;
    }

    private function comment(CoreUserModel $author, SnkKilkanaPostModel $post, string $body, string $status, ?SnkKilkanaCommentModel $parent = null): SnkKilkanaCommentModel
    {
        return SnkKilkanaCommentModel::create([
            'post_id' => $post->id,
            'user_id' => $author->id,
            'parent_id' => $parent?->id,
            'body' => $body,
            'status' => $status,
        ]);
    }

    private function makePost(string $title): SnkKilkanaPostModel
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
        ]);
    }
}
