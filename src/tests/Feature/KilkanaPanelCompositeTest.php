<?php

namespace Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaActivityController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaAuthorsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaCategoriesController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaCommentsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaGateController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaNewsletterController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPanelController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPostsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaReadersController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaTagsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaTrashController;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Kilkana\Models\SnkKilkanaGateResultModel;
use Packages\Kilkana\Models\SnkKilkanaNewsletterIssueModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * Las dos pantallas que el panel pide enteras.
 *
 * Lo que se fija aquí es lo único que puede romperse en silencio: **que cada sección del
 * compuesto sea exactamente lo que devuelve el endpoint al que sustituye**. Si alguna de las
 * dos formas se mueve sin la otra, el panel deja de leer una pantalla y nadie se entera hasta
 * que alguien la abre. Y que la sección que quien mira no puede ver llegue nula en vez de
 * tumbar la pantalla entera con un 403.
 */
class KilkanaPanelCompositeTest extends TestCase
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

    public function test_el_armazon_trae_seccion_a_seccion_lo_mismo_que_las_nueve_llamadas(): void
    {
        $this->comoDirectora();
        $this->contenido();

        $panel = $this->cuerpo(app(SnkKilkanaPanelController::class)->bootstrap());

        $this->assertSame($this->de(SnkKilkanaCategoriesController::class), $panel['categories']);
        $this->assertSame($this->de(SnkKilkanaAuthorsController::class), $panel['authors']);
        $this->assertSame($this->de(SnkKilkanaTagsController::class), $panel['tags']);
        $this->assertSame($this->de(SnkKilkanaCommentsController::class, ['counts' => '1']), $panel['comments']);
        $this->assertSame($this->de(SnkKilkanaReadersController::class, ['counts' => '1']), $panel['readers']);
        $this->assertSame($this->de(SnkKilkanaActivityController::class, ['count' => '6']), $panel['activity']);
        $this->assertSame($this->de(SnkKilkanaTrashController::class, ['counts' => '1']), $panel['trash']);
        $this->assertSame($this->de(SnkKilkanaGateController::class, ['counts' => '1']), $panel['gate']);
        $this->assertSame($this->de(SnkKilkanaNewsletterController::class, ['counts' => '1']), $panel['newsletter']);

        // Y trae algo: una comparación entre dos vacíos pasaría igual.
        $this->assertCount(1, $panel['categories']);
        $this->assertSame(1, $panel['comments']['counts']['pending']);
        $this->assertSame(1, $panel['gate']['failing']);
        $this->assertSame(1, $panel['newsletter']['drafts']);
        $this->assertSame(1, $panel['trash']['count']);
        $this->assertNotSame([], $panel['activity']['data']);
    }

    /** Quien no puede ver una sección la recibe nula, y el resto de la pantalla llega igual. */
    public function test_lo_que_no_se_puede_ver_llega_nulo_y_no_cierra_el_armazon(): void
    {
        $this->contenido();
        $elena = CoreUserModel::create(['name' => 'Elena', 'email' => 'elena@eldiario.example', 'password' => 'secret1234']);
        $elena->assignRole('moderador');
        $this->actingAs($elena, 'sanctum');

        $panel = $this->cuerpo(app(SnkKilkanaPanelController::class)->bootstrap());

        // El moderador no tiene ninguno de los tres catálogos, y sin embargo tiene panel.
        $this->assertNull($panel['categories']);
        $this->assertNull($panel['authors']);
        $this->assertNull($panel['tags']);
        // El gate y la campana se leen con permisos de piezas, que el moderador sí tiene.
        $this->assertSame(1, $panel['gate']['failing']);
        $this->assertSame(1, $panel['comments']['counts']['pending'], 'Y lo suyo sí lo ve.');
        $this->assertSame(1, $panel['newsletter']['drafts']);
    }

    public function test_el_tablero_trae_las_cuatro_colas_igual_que_las_cuatro_llamadas(): void
    {
        $this->comoDirectora();
        $this->contenido();

        $tablero = $this->cuerpo(app(SnkKilkanaPanelController::class)->dashboard());

        $this->assertSame($this->piezas('status=review&sort=date&dir=asc&per_page=5'), $tablero['review']);
        $this->assertSame($this->piezas('status=scheduled&sort=date&dir=asc&per_page=5'), $tablero['scheduled']);
        $this->assertSame($this->piezas('status=published&per_page=5'), $tablero['published']);
        $this->assertSame($this->piezas('sort=reads&per_page=6'), $tablero['reads']);

        $this->assertSame(1, $tablero['review']['meta']['total']);
        $this->assertSame(1, $tablero['published']['meta']['total']);
        // Las cifras de la barra viajan con las cuatro y se calculan una vez.
        $this->assertSame($tablero['review']['counts'], $tablero['reads']['counts']);
    }

    /** La ventana de «lo más leído» es la del listado: siete, treinta, o desde siempre. */
    public function test_la_ventana_de_lo_mas_leido_no_la_elige_quien_llama(): void
    {
        $this->comoDirectora();
        $this->contenido();

        foreach (['7' => 7, '30' => 30, '1000' => null, 'banana' => null] as $pedida => $_) {
            request()->query->replace(['reads' => (string) $pedida]);
            $tablero = $this->cuerpo(app(SnkKilkanaPanelController::class)->dashboard());
            $this->assertArrayHasKey('data', $tablero['reads'], "Con reads={$pedida} la cola sigue existiendo.");
        }
    }

    /** @return array<string, mixed> */
    private function cuerpo(JsonResponse $respuesta): array
    {
        return $respuesta->getData(true);
    }

    /** El cuerpo del endpoint al que sustituye una sección. */
    private function de(string $controlador, array $query = []): mixed
    {
        request()->query->replace($query);

        return app($controlador)->index()->getData(true);
    }

    /** Una de las cuatro colas del tablero, pedida como la pedía el panel. */
    private function piezas(string $query): array
    {
        parse_str($query, $params);
        request()->query->replace($params);

        return app(SnkKilkanaPostsController::class)->index()->getData(true);
    }

    private function comoDirectora(): CoreUserModel
    {
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');

        return $marta;
    }

    /** Una pieza de cada estado, un comentario pendiente, algo tirado y un veredicto que falla. */
    private function contenido(): void
    {
        $seccion = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $firma = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        SnkKilkanaTagModel::create(['slug' => 'clima', 'name' => 'Clima']);

        $comun = ['category_id' => $seccion->id, 'author_id' => $firma->id, 'excerpt' => 'Entradilla.'];
        $publicada = SnkKilkanaPostModel::create([...$comun, 'title' => 'Publicada', 'slug' => 'publicada', 'status' => 'published', 'publish_at' => now()->subHour()]);
        SnkKilkanaPostModel::create([...$comun, 'title' => 'En revisión', 'slug' => 'en-revision', 'status' => 'review']);
        SnkKilkanaPostModel::create([...$comun, 'title' => 'Programada', 'slug' => 'programada', 'status' => 'scheduled', 'publish_at' => now()->addDay()]);
        SnkKilkanaPostModel::create([...$comun, 'title' => 'Tirada', 'slug' => 'tirada', 'status' => 'draft'])->delete();

        SnkKilkanaCommentModel::create(['post_id' => $publicada->id, 'body' => 'Hola', 'status' => 'pending']);
        SnkKilkanaNewsletterIssueModel::create(['subject' => 'Domingo', 'status' => SnkKilkanaNewsletterIssueModel::DRAFT]);
        SnkKilkanaGateResultModel::create([
            'url' => '/', 'kind' => 'front', 'checked_at' => now(),
            'passing' => array_fill(0, SnkKilkanaGateResultModel::CHECKS - 1, true) + [SnkKilkanaGateResultModel::CHECKS - 1 => false],
        ]);
    }
}
