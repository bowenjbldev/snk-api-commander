<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPostsController;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaPostRevisionModel;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * Lo que se escribe por la puerta de artículos: alta, guardado, lote e historial.
 *
 * Existe porque **seis de las ocho acciones de este controlador no las ejecutaba ninguna
 * prueba**: el alta entera, el lote, el historial, la restauración, el muro de publicación en
 * sus tres puertas y el 404 de lo ajeno. Todo eso se puede romper sin que la suite se entere,
 * y lo que viene después es mover ese código de sitio.
 *
 * La forma del 422 es contrato y aquí se fija: **el objeto de errores va pelado, en la raíz**.
 * Envolverlo en `{message, errors}` —que es lo que hace un `FormRequest` al lanzar— cambiaría
 * lo que el panel parsea sin que ninguna otra prueba lo notase.
 */
class KilkanaPostsWriteTest extends TestCase
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
        $this->mundo();
    }

    public function test_el_alta_devuelve_la_pieza_y_lo_que_falla_va_en_la_raiz(): void
    {
        $this->como('director');

        $alta = $this->controlador()->store($this->peticion(['title' => 'El pleno aprueba el presupuesto']));

        $this->assertSame(201, $alta->getStatusCode());
        $this->assertSame('El pleno aprueba el presupuesto', $alta->getData(true)['title']);

        $rota = $this->controlador()->store($this->peticion(['title' => '', 'category' => 'no-existe']));

        $this->assertSame(422, $rota->getStatusCode());
        $cuerpo = $rota->getData(true);
        $this->assertArrayHasKey('title', $cuerpo, 'Los errores van en la raíz, no anidados.');
        $this->assertArrayHasKey('category', $cuerpo);
        $this->assertArrayNotHasKey('errors', $cuerpo, 'Ni envueltos: el panel lee la raíz.');
        $this->assertArrayNotHasKey('message', $cuerpo);
    }

    /** El muro entre escribir y publicar, en sus tres puertas. */
    public function test_publicar_no_lo_decide_quien_escribe(): void
    {
        $redactor = $this->como('redactor');
        $suya = $this->pieza('Lo mío', $redactor->id);
        $version = SnkKilkanaPostRevisionModel::create([
            'post_id' => $suya->id,
            'user_id' => $redactor->id,
            'payload' => ['title' => 'Lo mío', 'status' => 'published'],
        ]);

        $alta = $this->controlador()->store($this->peticion(['title' => 'Sale ya', 'status' => 'published', 'excerpt' => 'E.', 'publish_at' => '2026-07-01 10:00:00']));
        $guardado = $this->controlador()->update($this->peticion(['status' => 'published']), $suya->id);
        $restaurado = $this->controlador()->restore($suya->id, $version->id);

        foreach (['alta' => $alta, 'guardado' => $guardado, 'restauración' => $restaurado] as $puerta => $respuesta) {
            $this->assertSame(403, $respuesta->getStatusCode(), "La {$puerta} tiene que parar sin `publish posts`.");
            $this->assertSame('publish posts', $respuesta->getData(true)['permission'], "Y decir cuál falta ({$puerta}).");
        }
    }

    /**
     * Publicar es AHORA, y por eso la fecha no se le pide a nadie.
     *
     * Un borrador no tiene `publish_at` —se sella al salir—, así que validar solo el cuerpo
     * hacía que `required_if` rechazase: desde el editor no se podía publicar una pieza.
     */
    public function test_publicar_un_borrador_no_pide_ni_la_fecha_ni_la_entradilla(): void
    {
        $marta = $this->como('director');
        $borrador = $this->pieza('Sin fecha', $marta->id);

        $guardado = $this->controlador()->update($this->peticion(['status' => 'published']), $borrador->id);

        $this->assertSame(200, $guardado->getStatusCode(), $guardado->getContent());
        $this->assertNotNull($borrador->fresh()->publish_at, 'Se sella al salir.');
        $this->assertSame('published', $borrador->fresh()->status->value);
    }

    /** Una pieza ajena no existe: 404, y no 403, que confirmaría cuántas hay. */
    public function test_una_pieza_ajena_no_existe_para_quien_no_puede_tocarla(): void
    {
        $marta = $this->como('director');
        $suya = $this->pieza('De la jefa', $marta->id);
        $this->como('redactor');

        $this->assertSame(404, $this->controlador()->update($this->peticion(['title' => 'Mía ahora']), $suya->id)->getStatusCode());
        $this->assertSame(404, $this->controlador()->revisions($suya->id)->getStatusCode());
        $this->assertSame('De la jefa', $suya->fresh()->title, 'Y no la toca.');
    }

    public function test_el_lote_pide_el_permiso_de_lo_que_hace_y_dice_cuantas_movio(): void
    {
        $redactor = $this->como('redactor');
        $una = $this->pieza('Una', $redactor->id);
        $otra = $this->pieza('Otra', $redactor->id);
        $ids = [$una->id, $otra->id];

        $movidas = $this->controlador()->bulk(Request::create('/', 'POST', ['ids' => $ids, 'action' => 'review']));

        $this->assertSame(['ok' => true, 'count' => 2], $movidas->getData(true));
        $this->assertSame('review', $una->fresh()->status->value);

        $publicar = $this->controlador()->bulk(Request::create('/', 'POST', ['ids' => $ids, 'action' => 'publish']));

        $this->assertSame(403, $publicar->getStatusCode(), 'Publicar en tanda es publicar.');
        $this->assertSame('publish posts', $publicar->getData(true)['permission']);
    }

    public function test_el_historial_se_lee_con_las_llaves_de_la_pieza_y_restaurar_la_devuelve(): void
    {
        $marta = $this->como('director');
        $pieza = $this->pieza('Titular viejo', $marta->id);
        $this->controlador()->update($this->peticion(['title' => 'Titular nuevo']), $pieza->id);

        $historial = $this->controlador()->revisions($pieza->id)->getData(true);

        $this->assertCount(1, $historial, 'Guardar deja una versión.');
        $this->assertSame(404, $this->controlador()->revisions($pieza->id + 99)->getStatusCode());

        $vuelta = $this->controlador()->restore($pieza->id, $historial[0]['id']);

        $this->assertSame(200, $vuelta->getStatusCode(), $vuelta->getContent());
        $this->assertSame('Titular viejo', $pieza->fresh()->title, 'Restaurar devuelve el titular de esa versión.');
        $this->assertSame(1, DB::table('kilkana_activity')->where('verb', 'restored an earlier version of')->count());
    }

    private function controlador(): SnkKilkanaPostsController
    {
        return app(SnkKilkanaPostsController::class);
    }

    /** El cuerpo mínimo que el alta acepta, con lo que se le quiera cambiar encima. */
    private function peticion(array $cambios = []): Request
    {
        return Request::create('/', 'PUT', [
            'title' => 'Un titular',
            'category' => 'local',
            'author_id' => SnkKilkanaAuthorModel::query()->toBase()->value('id'),
            'status' => 'draft',
            ...$cambios,
        ]);
    }

    private function como(string $rol): CoreUserModel
    {
        $cuenta = CoreUserModel::create([
            'name' => ucfirst($rol),
            'email' => "{$rol}@eldiario.example",
            'password' => 'secret1234',
        ]);
        $cuenta->assignRole($rol);
        $this->actingAs($cuenta, 'sanctum');

        return $cuenta;
    }

    private function pieza(string $titular, int $de): SnkKilkanaPostModel
    {
        return SnkKilkanaPostModel::create([
            'title' => $titular,
            'excerpt' => 'Entradilla.',
            'category_id' => SnkKilkanaCategoryModel::query()->value('id'),
            'author_id' => SnkKilkanaAuthorModel::query()->toBase()->value('id'),
            'status' => 'draft',
            'created_by' => $de,
            'updated_by' => $de,
        ]);
    }

    private function mundo(): void
    {
        SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
    }
}
