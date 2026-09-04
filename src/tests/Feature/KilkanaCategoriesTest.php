<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Enums\SnkKilkanaCategoryStatusEnum;
use Packages\Kilkana\Http\Controllers\SnkKilkanaCategoriesController;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Services\SnkKilkanaPublicService;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * La seccion retirada del menu, y lo que el listado del panel necesita saber de cada una.
 *
 * `hidden` no es un tercer sinonimo de «no publicada»: es el unico estado con el que una
 * redaccion deja de ofrecer una seccion **sin romper lo que ya esta enlazado**. De ahi que
 * cada prueba de aqui mire una de las dos mitades — sigue abriendose, ya no se anuncia — y
 * que las dos esten separadas: juntarlas es exactamente el error que `draft` cometia.
 */
class KilkanaCategoriesTest extends TestCase
{
    private SnkKilkanaPublicService $public;

    protected function setUp(): void
    {
        parent::setUp();

        // `tenant` es sqlite `:memory:` en pruebas (`phpunit.xml`), así que la base vive
        // mientras viva la conexión: basta con migrar encima y no hay nada que limpiar.
        DB::setDefaultConnection('tenant');
        foreach (['Core', 'Kilkana'] as $paquete) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$paquete}/database/migrations",
            ])->run();
        }

        $this->public = app(SnkKilkanaPublicService::class);
    }

    public function test_una_seccion_oculta_se_abre_por_su_url_y_no_sale_en_el_menu(): void
    {
        $this->category('Motor', 'motor', 'live');
        $oculta = $this->category('Sucesos', 'sucesos', 'hidden');

        $menu = $this->public->categories()->pluck('slug')->all();
        $this->assertSame(['motor'], $menu, 'Una sección oculta no se ofrece en la navegación.');

        $abierta = $this->public->category('sucesos');
        $this->assertInstanceOf(SnkKilkanaCategoryModel::class, $abierta);
        $this->assertSame($oculta->id, $abierta->id, 'Su URL sigue respondiendo: es su definición.');
    }

    /**
     * Las dos formas de dejar de ofrecer una sección con archivo, y solo una es legítima.
     *
     * `draft` no admite piezas publicadas —sus URLs dejarían de responder—, y ese muro ya
     * existía; lo que faltaba era la salida que no rompe nada.
     */
    public function test_una_seccion_con_piezas_publicadas_se_oculta_pero_no_vuelve_a_preparacion(): void
    {
        $seccion = $this->category('Cultura', 'cultura', 'live');
        $this->makePost('Estreno', $seccion, $this->author('Ada Prat'), 'published', '2026-08-01 08:00:00');

        $seccion->update(['status' => SnkKilkanaCategoryStatusEnum::Hidden]);
        $this->assertSame(SnkKilkanaCategoryStatusEnum::Hidden, $seccion->fresh()->status);

        $this->expectException(ValidationException::class);
        $seccion->update(['status' => SnkKilkanaCategoryStatusEnum::Draft]);
    }

    /** El estado es cocina de la redacción: la calle no tiene por qué saber qué se retiró. */
    public function test_la_calle_no_dice_el_estado_de_una_seccion(): void
    {
        $this->category('Motor', 'motor', 'live');
        $this->category('Agenda', 'agenda', 'hidden');

        $menu = $this->public->categories()->first()->toArray();
        $oculta = $this->public->category('agenda');

        $this->assertArrayNotHasKey('status', $menu);
        $this->assertArrayNotHasKey('position', $menu);
        $this->assertInstanceOf(SnkKilkanaCategoryModel::class, $oculta);
        $this->assertArrayNotHasKey('status', $oculta->toArray());
    }

    /** Publicar en una oculta sí: el lector puede abrir la pieza y la sección que la firma. */
    public function test_se_publica_en_una_seccion_oculta_y_no_en_una_en_preparacion(): void
    {
        $oculta = $this->category('Opinión', 'opinion', 'hidden');
        $preparacion = $this->category('Podcast', 'podcast', 'draft');
        $firma = $this->author('Ada Prat');

        $this->makePost('Columna', $oculta, $firma, 'published', '2026-08-02 08:00:00');
        $this->assertSame(1, SnkKilkanaPostModel::query()->where('category_id', $oculta->id)->count());

        $this->expectException(ValidationException::class);
        $this->makePost('Piloto', $preparacion, $firma, 'published', '2026-08-02 09:00:00');
    }

    /**
     * El muro mira el arbol entero y no solo lo que cuelga directo.
     *
     * Una madre sin piezas propias y con una hija publicando pasaba a preparacion sin que nada
     * la parara: la hija seguia alcanzable, sus piezas publicas, y la pagina de la madre
     * respondia 404. Se llega ahi en cuatro pasos desde el panel.
     */
    public function test_una_madre_no_vuelve_a_preparacion_si_publica_una_subseccion(): void
    {
        $madre = $this->category('Deportes', 'deportes', 'live');
        $hija = SnkKilkanaCategoryModel::create([
            'name' => 'Futbol',
            'slug' => 'futbol',
            'status' => 'live',
            'position' => 0,
            'parent_id' => $madre->id,
        ]);
        $this->makePost('Derbi', $hija, $this->author('Ada Prat'), 'published', '2026-08-03 08:00:00');

        // La madre no archiva ni una pieza suya.
        $this->assertSame(0, SnkKilkanaPostModel::query()->where('category_id', $madre->id)->count());

        $this->expectException(ValidationException::class);
        $madre->update(['status' => SnkKilkanaCategoryStatusEnum::Draft]);
    }

    /**
     * La puerta que `guardStatus` no cubre: la jerarquía.
     *
     * Cerrar solo el estado deja llegar al mismo sitio prohibido por el otro lado —mover una
     * sección viva bajo una madre en preparación—, y ahí la madre responde 404 mientras su
     * hija y las piezas de su hija siguen abriéndose.
     */
    public function test_una_seccion_no_cuelga_de_una_madre_menos_visible(): void
    {
        $preparacion = $this->category('Medio ambiente', 'medio-ambiente', 'draft');
        $viva = $this->category('Politica', 'politica', 'live');

        $this->expectException(ValidationException::class);
        $viva->update(['parent_id' => $preparacion->id]);
    }

    /** Y `hidden` cuenta como menos visible: el menú de una hija cuelga del de su madre. */
    public function test_una_hija_viva_no_cuelga_de_una_madre_retirada_del_menu(): void
    {
        $oculta = $this->category('Cultura', 'cultura', 'hidden');
        $viva = $this->category('Agenda', 'agenda', 'live');

        // Al revés sí: una oculta bajo una viva es exactamente lo que `hidden` significa.
        $alReves = $this->category('Sucesos', 'sucesos', 'hidden');
        $madre = $this->category('Local', 'local', 'live');
        $alReves->update(['parent_id' => $madre->id]);
        $this->assertSame($madre->id, $alReves->fresh()->parent_id);

        $this->expectException(ValidationException::class);
        $viva->update(['parent_id' => $oculta->id]);
    }

    /** Y sin madre que mirar no se pasa: la puerta falla cerrada, como el resto. */
    public function test_una_madre_que_no_existe_no_cuela(): void
    {
        $seccion = $this->category('Agenda', 'agenda', 'draft');

        $this->expectException(ValidationException::class);
        // `guardTree` no lo caza: su consulta devuelve null igual que la de una raiz.
        $seccion->update(['parent_id' => 9999]);
    }

    /** Y la misma regla mirando hacia abajo: bajar el estado de una madre con hijas más visibles. */
    public function test_una_madre_no_baja_de_estado_por_debajo_de_sus_hijas(): void
    {
        $madre = $this->category('Deportes', 'deportes', 'live');
        SnkKilkanaCategoryModel::create([
            'name' => 'Futbol',
            'slug' => 'futbol',
            'status' => 'live',
            'position' => 0,
            'parent_id' => $madre->id,
        ]);

        $this->expectException(ValidationException::class);
        $madre->update(['status' => SnkKilkanaCategoryStatusEnum::Hidden]);
    }

    /**
     * Lo que el listado del panel no puede calcular por su cuenta.
     *
     * Las tres columnas cuestan una subconsulta por fila; la alternativa era traerse el
     * archivo entero al cliente y contarlo allí. `last_published_at` mira solo lo publicado:
     * un borrador de hoy no es actividad de la sección.
     */
    public function test_el_listado_trae_recuento_ultima_publicada_y_orden_de_menu(): void
    {
        $cultura = $this->category('Cultura', 'cultura', 'live', 1);
        $local = $this->category('Local', 'local', 'live', 0);
        $firma = $this->author('Ada Prat');

        $this->makePost('Vieja', $local, $firma, 'published', '2026-07-01 08:00:00');
        $this->makePost('Nueva', $local, $firma, 'published', '2026-08-01 08:00:00');
        $this->makePost('Sin publicar', $local, $firma, 'draft');

        $filas = $this->index();

        $this->assertSame(['local', 'cultura'], array_column($filas, 'slug'), 'Ordena por posición.');
        $this->assertSame(3, $filas[0]['posts_count'], 'Cuenta el archivo entero, publicado o no.');
        $this->assertStringStartsWith('2026-08-01', (string) $filas[0]['last_published_at']);
        $this->assertNull($filas[1]['last_published_at'], 'Una sección sin nada publicado no tiene fecha.');
        $this->assertSame($cultura->id, $filas[1]['id']);
    }

    /** La URL que se deja atrás viaja con la sección: es lo que el panel enseña como 301. */
    public function test_el_listado_trae_las_urls_que_la_seccion_dejo_atras(): void
    {
        $seccion = $this->category('Tecnología', 'tecnologia', 'live');
        $seccion->update(['slug' => 'tech']);

        $filas = $this->index();

        $this->assertSame('tech', $filas[0]['slug']);
        $this->assertSame(['tecnologia'], array_column($filas[0]['retired_slugs'], 'path'));
    }

    /** El listado tal como sale por la API, sin pasar por HTTP: lo que se prueba es la consulta. */
    private function index(): array
    {
        $controlador = new class extends SnkKilkanaCategoriesController
        {
            public function listado(): array
            {
                return $this->newQuery()->get()->toArray();
            }
        };

        return $controlador->listado();
    }

    private function category(string $name, string $slug, string $status, int $position = 0): SnkKilkanaCategoryModel
    {
        return SnkKilkanaCategoryModel::create([
            'name' => $name,
            'slug' => $slug,
            'status' => $status,
            'position' => $position,
        ]);
    }

    private function author(string $accountName): SnkKilkanaAuthorModel
    {
        $user = CoreUserModel::create([
            'name' => $accountName,
            'email' => str()->slug($accountName).'@eldiario.example',
            'password' => 'secret1234',
        ]);

        return SnkKilkanaAuthorModel::create([
            'user_id' => $user->id,
            'slug' => str()->slug($accountName),
        ]);
    }

    private function makePost(
        string $title,
        SnkKilkanaCategoryModel $category,
        SnkKilkanaAuthorModel $author,
        string $status,
        ?string $publishAt = null,
    ): SnkKilkanaPostModel {
        return SnkKilkanaPostModel::create([
            'title' => $title,
            'excerpt' => 'Entradilla de prueba.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => $status,
            'publish_at' => $publishAt,
            'url_date' => $status === 'published' ? substr((string) $publishAt, 0, 10) : null,
            'created_by' => 1,
            'updated_by' => 1,
        ]);
    }

    public function test_una_seccion_no_puede_llamarse_como_una_ruta_fija_de_la_calle(): void
    {
        foreach ([['name' => 'Subscribe'], ['name' => 'Cuentas', 'slug' => 'admin']] as $datos) {
            try {
                SnkKilkanaCategoryModel::create($datos + ['status' => 'live', 'position' => 0]);
                $this->fail('Se guardó una sección cuya página pública no existirá nunca.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('slug', $e->errors());
            }
        }
        $this->assertSame(0, SnkKilkanaCategoryModel::query()->count());
    }

    /** Un slug dado a mano tiene que tener forma de slug: con punto, barra o mayúscula no casa con ninguna ruta. */
    public function test_un_slug_escrito_a_mano_sin_forma_de_slug_no_entra(): void
    {
        app(SnkKilkanaRoleCatalogService::class)->sync();
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
        $crear = fn (string $slug) => app(SnkKilkanaCategoriesController::class)->store(Request::create('/', 'POST', ['name' => 'Prueba', 'slug' => $slug]));

        foreach (['robots.txt', 'con/barra', 'Mayus', 'con espacio', '-borde-'] as $slug) {
            $this->assertSame(422, $crear($slug)->getStatusCode(), $slug);
        }
        $this->assertSame(201, $crear('con-guion-2')->getStatusCode());
    }
}
