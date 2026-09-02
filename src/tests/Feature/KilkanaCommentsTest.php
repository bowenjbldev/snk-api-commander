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
 * La moderacion no es un archivo que se consulta: es una cola que se vacia.
 *
 * Lo que se fija aqui es lo que hace falta para decidir sin abrir nada mas —la pieza, la
 * frase de arriba y el historial de la cuenta—, que rechazar no es ni spam ni borrar, y que
 * bloquear a quien escribe cierra la cuenta y vacia lo que tenia en la cola.
 */
class KilkanaCommentsTest extends TestCase
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
    }

    public function test_la_cola_trae_la_pieza_la_frase_de_arriba_y_el_historial_de_la_cuenta(): void
    {
        $this->comoModerador();
        $amparo = $this->lector('amparo@lectores.example', 'Amparo Ruiz');
        $pieza = $this->pieza('Plan de aparcamientos');
        $primero = $this->comentario($amparo, $pieza, 'Las plazas ya estaban saturadas.', 'approved');
        $this->comentario($amparo, $pieza, 'Insulto que no sale.', 'rejected');
        $respuesta = $this->comentario($amparo, $pieza, 'Y en Navidad sera inviable.', 'pending', $primero);

        $lista = $this->moderacion()->index()->getData(true);

        $this->assertSame(['pending' => 1, 'approved' => 1, 'rejected' => 1, 'spam' => 0, 'all' => 3], $lista['counts']);
        $this->assertSame(3, $lista['meta']['total']);
        $this->assertSame($respuesta->created_at->toJSON(), $lista['oldest_pending_at'] ? Carbon::parse($lista['oldest_pending_at'])->toJSON() : null);

        $fila = collect($lista['data'])->firstWhere('id', $respuesta->id);
        $this->assertSame('Plan de aparcamientos', $fila['on']['title']);
        $this->assertSame('Amparo Ruiz', $fila['reply_to']['author']);
        $this->assertSame('Las plazas ya estaban saturadas.', $fila['reply_to']['body']);
        $this->assertSame(['total' => 3, 'rejected' => 1, 'blocked' => false], array_intersect_key($fila['author'], ['total' => 1, 'rejected' => 1, 'blocked' => 1]));
        $this->assertSame('Amparo Ruiz', $fila['author']['name']);
    }

    /** Abre en pendiente y la busqueda mira el texto y a quien lo firma. */
    public function test_la_cola_se_filtra_por_estado_y_por_lo_tecleado(): void
    {
        $this->comoModerador();
        $amparo = $this->lector('amparo@lectores.example', 'Amparo Ruiz');
        $joaquin = $this->lector('joaquin@lectores.example', 'Joaquin Vidal');
        $pieza = $this->pieza('Plan de aparcamientos');
        $this->comentario($amparo, $pieza, 'Las plazas ya estaban saturadas.', 'pending');
        $this->comentario($joaquin, $pieza, 'El horario de carga deja fuera al reparto.', 'approved');

        $pendientes = $this->moderacion(['status' => 'pending'])->index()->getData(true);
        $this->assertCount(1, $pendientes['data']);
        $this->assertSame('pending', $pendientes['data'][0]['status']);

        $joaquin = $this->moderacion(['q' => 'vidal'])->index()->getData(true);
        $this->assertCount(1, $joaquin['data']);
        $this->assertSame('Joaquin Vidal', $joaquin['data'][0]['author']['name']);

        $texto = $this->moderacion(['q' => 'saturadas'])->index()->getData(true);
        $this->assertCount(1, $texto['data']);
    }

    /** Rechazar no es spam ni es borrar: el comentario se queda, no se ve, y cuenta en el historial. */
    public function test_rechazar_es_un_desenlace_propio(): void
    {
        $this->comoModerador();
        $amparo = $this->lector('amparo@lectores.example', 'Amparo Ruiz');
        $pieza = $this->pieza('Plan de aparcamientos');
        $c = $this->comentario($amparo, $pieza, 'No sale.', 'pending');

        $respuesta = $this->moderacion()->bulk(Request::create('/', 'POST', ['ids' => [$c->id], 'action' => 'reject']));

        $this->assertSame(1, $respuesta->getData(true)['count']);
        $this->assertSame(SnkKilkanaCommentStatusEnum::Rejected, $c->fresh()->status);
        $this->assertFalse($c->fresh()->status->isPublic());
        $this->assertSame(0, SnkKilkanaCommentModel::query()->approved()->count());
    }

    public function test_bloquear_al_autor_cierra_su_cuenta_y_vacia_su_cola(): void
    {
        $this->comoModerador();
        $amparo = $this->lector('amparo@lectores.example', 'Amparo Ruiz');
        $amparo->createToken('login');
        $pieza = $this->pieza('Plan de aparcamientos');
        $este = $this->comentario($amparo, $pieza, 'Insulto.', 'pending');
        $this->comentario($amparo, $pieza, 'Otro insulto.', 'pending');
        $antiguo = $this->comentario($amparo, $pieza, 'Uno de antes.', 'approved');

        $respuesta = $this->moderacion()->blockAuthor($este->id);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame(2, $respuesta->getData(true)['rejected']);
        $this->assertNotNull($amparo->fresh()->suspended_at);
        $this->assertSame(0, $amparo->tokens()->count(), 'Sus sesiones se cortan.');
        $this->assertSame(SnkKilkanaCommentStatusEnum::Approved, $antiguo->fresh()->status, 'Lo ya publicado no se toca.');

        $login = (new SnkKilkanaAuthController)->login(Request::create('/', 'POST', ['email' => 'amparo@lectores.example', 'password' => 'secret1234']));
        $this->assertSame(403, $login->getStatusCode());

        $lista = $this->moderacion()->index()->getData(true);
        $this->assertSame(1, $lista['blocked_readers']);
        $this->assertTrue(collect($lista['data'])->firstWhere('id', $este->id)['author']['blocked']);
    }

    /** A un companero no se le bloquea desde la moderacion, y a una cuenta dada de baja tampoco. */
    public function test_solo_se_bloquea_a_un_lector(): void
    {
        $this->comoModerador();
        $redactora = CoreUserModel::create(['name' => 'Lucia', 'email' => 'lucia@eldiario.example', 'password' => 'secret1234']);
        $redactora->assignRole('redactor');
        $pieza = $this->pieza('Plan de aparcamientos');
        $deLaCasa = $this->comentario($redactora, $pieza, 'Gracias por el aviso.', 'approved');
        $huerfano = SnkKilkanaCommentModel::create(['post_id' => $pieza->id, 'user_id' => null, 'body' => 'Sin cuenta.', 'status' => 'pending']);

        $this->assertSame(409, $this->moderacion()->blockAuthor($deLaCasa->id)->getStatusCode());
        $this->assertNull($redactora->fresh()->suspended_at);

        // Lector Y redactora a la vez: sigue siendo un companero. La guarda falla cerrado.
        $redactora->assignRole('reader');
        $this->assertSame(409, $this->moderacion()->blockAuthor($deLaCasa->id)->getStatusCode());
        $this->assertNull($redactora->fresh()->suspended_at);
        $this->assertSame(409, $this->moderacion()->blockAuthor($huerfano->id)->getStatusCode());
        $this->assertSame(404, $this->moderacion()->blockAuthor(999)->getStatusCode());
    }

    /** El rail pregunta cuantos esperan en cada carga del armazon: solo la cifra, sin la pagina. */
    public function test_solo_la_cifra_para_el_rail(): void
    {
        $this->comoModerador();
        $amparo = $this->lector('amparo@lectores.example', 'Amparo Ruiz');
        $pieza = $this->pieza('Plan de aparcamientos');
        $this->comentario($amparo, $pieza, 'Uno.', 'pending');
        $this->comentario($amparo, $pieza, 'Dos.', 'spam');

        $cifra = $this->moderacion(['counts' => '1'])->index()->getData(true);

        $this->assertSame(['counts', 'oldest_pending_at'], array_keys($cifra));
        $this->assertSame(1, $cifra['counts']['pending']);
        $this->assertSame(2, $cifra['counts']['all']);
    }

    /** Moderar es del que modera: un redactor lee la cola y no decide. */
    public function test_quien_solo_lee_no_bloquea(): void
    {
        $redactor = CoreUserModel::create(['name' => 'Ivan', 'email' => 'ivan@eldiario.example', 'password' => 'secret1234']);
        $redactor->assignRole('redactor');
        $this->actingAs($redactor, 'sanctum');

        $this->assertSame(403, app(SnkKilkanaCommentsController::class)->blockAuthor(1)->getStatusCode());
    }

    /** El panel cuenta todo lo que hay que moderar; la calle, solo lo que puede ver. */
    public function test_la_calle_cuenta_solo_los_comentarios_aprobados(): void
    {
        $amparo = $this->lector('amparo@lectores.example', 'Amparo Ruiz');
        $pieza = $this->pieza('Plan de aparcamientos');
        $this->comentario($amparo, $pieza, 'Las plazas ya estaban saturadas.', 'approved');
        $this->comentario($amparo, $pieza, 'Insulto que no sale.', 'rejected');
        $this->comentario($amparo, $pieza, 'Compra aqui.', 'spam');

        $calle = app(SnkKilkanaPublicService::class);
        $lista = $calle->posts([])->first();
        $detalle = $calle->post('2026', '07', '01', $pieza->slug);

        $this->assertSame(3, $pieza->fresh()->comments_count, 'El panel ve que hay algo que moderar.');
        $this->assertSame(1, $lista->comments_count);
        $this->assertSame(1, $detalle->comments_count);
        $this->assertArrayNotHasKey('approved_comments_count', $detalle->toArray());
    }

    private function comoModerador(): CoreUserModel
    {
        $elena = CoreUserModel::create(['name' => 'Elena', 'email' => 'elena@eldiario.example', 'password' => 'secret1234']);
        $elena->assignRole('moderador');
        $this->actingAs($elena, 'sanctum');

        return $elena;
    }

    private function moderacion(array $query = []): SnkKilkanaCommentsController
    {
        request()->query->replace($query);

        return app(SnkKilkanaCommentsController::class);
    }

    private function lector(string $email, string $nombre): CoreUserModel
    {
        $lector = CoreUserModel::create(['name' => $nombre, 'email' => $email, 'password' => 'secret1234']);
        $lector->assignRole('reader');

        return $lector;
    }

    private function comentario(CoreUserModel $autor, SnkKilkanaPostModel $pieza, string $texto, string $estado, ?SnkKilkanaCommentModel $padre = null): SnkKilkanaCommentModel
    {
        return SnkKilkanaCommentModel::create([
            'post_id' => $pieza->id,
            'user_id' => $autor->id,
            'parent_id' => $padre?->id,
            'body' => $texto,
            'status' => $estado,
        ]);
    }

    private function pieza(string $titulo): SnkKilkanaPostModel
    {
        $seccion = SnkKilkanaCategoryModel::firstOrCreate(['slug' => 'local'], ['name' => 'Local', 'status' => 'live', 'position' => 0]);
        $firma = SnkKilkanaAuthorModel::firstOrCreate(['slug' => 'redaccion'], ['alias' => 'Redacción']);

        return SnkKilkanaPostModel::create([
            'title' => $titulo,
            'slug' => str($titulo)->slug()->value(),
            'url_date' => '2026-07-01',
            'category_id' => $seccion->id,
            'author_id' => $firma->id,
            'status' => 'published',
            'publish_at' => '2026-07-01 08:00:00',
        ]);
    }
}
