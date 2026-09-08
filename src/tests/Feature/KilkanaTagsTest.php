<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaTagsController;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * De un vocabulario no se mira la lista de palabras, se mira su FORMA.
 *
 * Y la forma son las cuentas: cuales cargan con el archivo, cuales son cola y cuando se uso
 * cada una por ultima vez. Sin ellas la pantalla de etiquetas es una lista alfabetica, y
 * fundir —su unica operacion de verdad— se decide a ciegas.
 */
class KilkanaTagsTest extends TestCase
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

        // Las claves foraneas, encendidas SOLO en esta conexion: sqlite las trae apagadas y la
        // del banco no declara `foreign_key_constraints`, asi que sin esto la cascada que
        // sostiene `detached` no se puede afirmar. Va despues de migrar —es por conexion, no por
        // tabla— y no toca al resto de la suite.
        DB::statement('PRAGMA foreign_keys = ON');

        app(SnkKilkanaRoleCatalogService::class)->sync();
    }

    /** Lo que la pantalla lee de cada fila sin abrirla. */
    public function test_el_listado_trae_el_uso_y_la_ultima_vez(): void
    {
        $movilidad = SnkKilkanaTagModel::create(['name' => 'movilidad']);
        $sequia = SnkKilkanaTagModel::create(['name' => 'sequia']);

        $this->pieza('Pleno de julio', '2026-07-01 08:00:00')->tags()->attach($movilidad);
        $this->pieza('Carril bici', '2026-07-09 10:00:00')->tags()->attach($movilidad);
        // Un borrador CUENTA como uso —hay que moverlo al fundir— pero no fecha el vocabulario:
        // una intencion de hoy no es que el tema siga vivo.
        $this->pieza('Sin publicar', '2026-08-30 09:00:00', 'draft')->tags()->attach($movilidad);

        $filas = collect($this->listado())->keyBy('name');

        $this->assertSame(3, $filas['movilidad']['posts_count']);
        $this->assertStringStartsWith('2026-07-09', (string) $filas['movilidad']['last_used_at']);
        $this->assertSame(0, $filas['sequia']['posts_count']);
        $this->assertNull($filas['sequia']['last_used_at'], 'Una etiqueta sin usar no tiene fecha que enseñar.');
        $this->assertSame(['movilidad', 'sequia'], collect($this->listado())->pluck('name')->all());
    }

    /**
     * Fundir mueve los usos, no los pierde.
     *
     * Es lo que separa fundir de borrar: la cifra de la superviviente tiene que seguir cuadrando
     * con las piezas que la llevan, y una pieza que llevara las dos no puede contarse dos veces.
     */
    public function test_fundir_mueve_las_piezas_y_no_duplica_la_que_llevaba_las_dos(): void
    {
        $urbana = SnkKilkanaTagModel::create(['name' => 'movilidad urbana']);
        $movilidad = SnkKilkanaTagModel::create(['name' => 'movilidad']);

        $solo = $this->pieza('Carril bici', '2026-07-09 10:00:00');
        $ambas = $this->pieza('Zona de bajas emisiones', '2026-07-10 10:00:00');
        $solo->tags()->attach($urbana);
        $ambas->tags()->attach([$urbana->id, $movilidad->id]);

        $respuesta = $this->comoDirector()->merge(Request::create('/', 'POST', ['into' => $movilidad->id]), $urbana->id);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame(2, $respuesta->getData(true)['moved']);
        $this->assertNull(SnkKilkanaTagModel::query()->find($urbana->id), 'La fundida desaparece.');
        $this->assertSame(2, $movilidad->posts()->count(), 'Y la superviviente se queda con las dos piezas, una vez cada una.');
    }

    /**
     * Borrar no pregunta, asi que la respuesta es lo unico que dice cuanto se ha llevado.
     *
     * Y el pivote cae detras, que es de lo que esa cifra se fia: `kilkana_post_tag.tag_id` es
     * `cascadeOnDelete`, y aqui se puede afirmar porque el `setUp` enciende las claves foraneas
     * de esta conexion.
     */
    public function test_borrar_dice_a_cuantas_piezas_ha_desetiquetado(): void
    {
        $pleno = SnkKilkanaTagModel::create(['name' => 'pleno']);
        $this->pieza('Pleno de julio', '2026-07-01 08:00:00')->tags()->attach($pleno);

        $respuesta = $this->comoDirector()->destroy($pleno->id);

        $this->assertSame(1, $respuesta->getData(true)['detached']);
        $this->assertNull(SnkKilkanaTagModel::query()->find($pleno->id));
        $this->assertSame(0, DB::table('kilkana_post_tag')->count(), 'El pivote se va con ella.');
    }

    /** Fundir una etiqueta consigo misma la borraria sin mover nada: se rechaza antes. */
    public function test_no_se_funde_una_etiqueta_en_si_misma(): void
    {
        $una = SnkKilkanaTagModel::create(['name' => 'movilidad']);

        $this->expectException(ValidationException::class);

        try {
            $this->comoDirector()->merge(Request::create('/', 'POST', ['into' => $una->id]), $una->id);
        } finally {
            $this->assertNotNull(SnkKilkanaTagModel::query()->find($una->id), 'Y sigue ahi.');
        }
    }

    /**
     * Fundir recorta el vocabulario, asi que pide el permiso de borrar.
     *
     * Un redactor puede crear etiquetas —las teclea al escribir— y ahi se acaba: dejarle fundir
     * seria dejarle borrar una etiqueta del periodico por la puerta de al lado.
     */
    public function test_quien_no_puede_borrar_no_puede_fundir(): void
    {
        $una = SnkKilkanaTagModel::create(['name' => 'movilidad']);
        $otra = SnkKilkanaTagModel::create(['name' => 'movilidad urbana']);

        $redactor = CoreUserModel::create(['name' => 'Iván', 'email' => 'ivan@eldiario.example', 'password' => 'secret1234']);
        $redactor->assignRole('redactor');
        $this->actingAs($redactor, 'sanctum');

        $respuesta = (new SnkKilkanaTagsController)->merge(Request::create('/', 'POST', ['into' => $otra->id]), $una->id);

        $this->assertSame(403, $respuesta->getStatusCode());
        $this->assertNotNull(SnkKilkanaTagModel::query()->find($una->id), 'Y sigue ahí.');
    }

    private function comoDirector(): SnkKilkanaTagsController
    {
        $usuario = CoreUserModel::firstOrCreate(
            ['email' => 'marta@eldiario.example'],
            ['name' => 'Marta', 'password' => 'secret1234']
        );
        $usuario->assignRole('director');
        $this->actingAs($usuario, 'sanctum');

        return new SnkKilkanaTagsController;
    }

    /** `?fields=` recorta la fila y las dos cifras siguen saliendo: se cuelgan después del recorte. */
    public function test_pedir_campos_recorta_la_fila_y_conserva_las_cifras(): void
    {
        $this->pieza('Pleno', '2026-07-01 08:00:00')->tags()->attach(SnkKilkanaTagModel::create(['name' => 'movilidad']));
        $this->app['request']->query->set('fields', 'name');

        $fila = $this->listado()[0];

        $this->assertEqualsCanonicalizing(['id', 'name', 'posts_count', 'last_used_at'], array_keys($fila));
        $this->assertSame(1, $fila['posts_count']);
    }

    /** @return array<int, array<string, mixed>> */
    private function listado(): array
    {
        $controlador = new class extends SnkKilkanaTagsController
        {
            /** @return array<int, array<string, mixed>> */
            public function filas(): array
            {
                return $this->queryBuilder()->get()->toArray();
            }
        };

        return $controlador->filas();
    }

    private function pieza(string $titulo, string $fecha, string $estado = 'published'): SnkKilkanaPostModel
    {
        $seccion = SnkKilkanaCategoryModel::firstOrCreate(
            ['slug' => 'local'],
            ['name' => 'Local', 'status' => 'live', 'position' => 0]
        );
        $firma = SnkKilkanaAuthorModel::firstOrCreate(['slug' => 'redaccion'], ['alias' => 'Redacción']);

        return SnkKilkanaPostModel::create([
            'title' => $titulo,
            'slug' => str($titulo)->slug()->value(),
            'url_date' => substr($fecha, 0, 10),
            'category_id' => $seccion->id,
            'author_id' => $firma->id,
            'status' => $estado,
            'publish_at' => $fecha,
        ]);
    }
}
