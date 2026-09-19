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
 * Y la forma son las cuentas: cuáles cargan con el archivo, cuáles son cola y cuándo se usó
 * cada una por última vez. Sin ellas la pantalla de etiquetas es una lista alfabética, y
 * fundir —su única operación de verdad— se decide a ciegas.
 */
class KilkanaTagsTest extends TestCase
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

        // Las claves foráneas, encendidas SOLO en esta conexión: sqlite las trae apagadas y la
        // del banco no declara `foreign_key_constraints`, así que sin esto la cascada que
        // sostiene `detached` no se puede afirmar. Va después de migrar —es por conexión, no por
        // tabla— y no toca al resto de la suite.
        DB::statement('PRAGMA foreign_keys = ON');

        app(SnkKilkanaRoleCatalogService::class)->sync();
    }

    /** Lo que la pantalla lee de cada fila sin abrirla. */
    public function test_the_list_includes_usage_and_last_used(): void
    {
        $mobility = SnkKilkanaTagModel::create(['name' => 'movilidad']);
        $drought = SnkKilkanaTagModel::create(['name' => 'sequia']);

        $this->makePost('Pleno de julio', '2026-07-01 08:00:00')->tags()->attach($mobility);
        $this->makePost('Carril bici', '2026-07-09 10:00:00')->tags()->attach($mobility);
        // Un borrador CUENTA como uso —hay que moverlo al fundir— pero no fecha el vocabulario:
        // una intención de hoy no es que el tema siga vivo.
        $this->makePost('Sin publicar', '2026-08-30 09:00:00', 'draft')->tags()->attach($mobility);

        $rows = collect($this->tagRows())->keyBy('name');

        $this->assertSame(3, $rows['movilidad']['posts_count']);
        $this->assertStringStartsWith('2026-07-09', (string) $rows['movilidad']['last_used_at']);
        $this->assertSame(0, $rows['sequia']['posts_count']);
        $this->assertNull($rows['sequia']['last_used_at'], 'Una etiqueta sin usar no tiene fecha que enseñar.');
        $this->assertSame(['movilidad', 'sequia'], collect($this->tagRows())->pluck('name')->all());
    }

    /**
     * Fundir mueve los usos, no los pierde.
     *
     * Es lo que separa fundir de borrar: la cifra de la superviviente tiene que seguir cuadrando
     * con las piezas que la llevan, y una pieza que llevara las dos no puede contarse dos veces.
     */
    public function test_merging_moves_posts_and_does_not_duplicate_one_that_had_both(): void
    {
        $urban = SnkKilkanaTagModel::create(['name' => 'movilidad urbana']);
        $mobility = SnkKilkanaTagModel::create(['name' => 'movilidad']);

        $urbanOnly = $this->makePost('Carril bici', '2026-07-09 10:00:00');
        $both = $this->makePost('Zona de bajas emisiones', '2026-07-10 10:00:00');
        $urbanOnly->tags()->attach($urban);
        $both->tags()->attach([$urban->id, $mobility->id]);

        $response = $this->asEditorInChief()->merge(Request::create('/', 'POST', ['into' => $mobility->id]), $urban->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $response->getData(true)['moved']);
        $this->assertNull(SnkKilkanaTagModel::query()->find($urban->id), 'La fundida desaparece.');
        $this->assertSame(2, $mobility->posts()->count(), 'Y la superviviente se queda con las dos piezas, una vez cada una.');
    }

    /**
     * Borrar no pregunta, así que la respuesta es lo único que dice cuánto se ha llevado.
     *
     * Y el pivote cae detrás, que es de lo que esa cifra se fía: `kilkana_post_tag.tag_id` es
     * `cascadeOnDelete`, y aquí se puede afirmar porque el `setUp` enciende las claves foráneas
     * de esta conexión.
     */
    public function test_deleting_reports_how_many_posts_were_untagged(): void
    {
        $plenary = SnkKilkanaTagModel::create(['name' => 'pleno']);
        $this->makePost('Pleno de julio', '2026-07-01 08:00:00')->tags()->attach($plenary);

        $response = $this->asEditorInChief()->destroy($plenary->id);

        $this->assertSame(1, $response->getData(true)['detached']);
        $this->assertNull(SnkKilkanaTagModel::query()->find($plenary->id));
        $this->assertSame(0, DB::table('kilkana_post_tag')->count(), 'El pivote se va con ella.');
    }

    /** Fundir una etiqueta consigo misma la borraría sin mover nada: se rechaza antes. */
    public function test_a_tag_cannot_be_merged_into_itself(): void
    {
        $one = SnkKilkanaTagModel::create(['name' => 'movilidad']);

        $this->expectException(ValidationException::class);

        try {
            $this->asEditorInChief()->merge(Request::create('/', 'POST', ['into' => $one->id]), $one->id);
        } finally {
            $this->assertNotNull(SnkKilkanaTagModel::query()->find($one->id), 'Y sigue ahí.');
        }
    }

    /**
     * Fundir recorta el vocabulario, así que pide el permiso de borrar.
     *
     * Un redactor puede crear etiquetas —las teclea al escribir— y ahí se acaba: dejarle fundir
     * sería dejarle borrar una etiqueta del periódico por la puerta de al lado.
     */
    public function test_whoever_cannot_delete_cannot_merge(): void
    {
        $one = SnkKilkanaTagModel::create(['name' => 'movilidad']);
        $other = SnkKilkanaTagModel::create(['name' => 'movilidad urbana']);

        $writer = CoreUserModel::create(['name' => 'Iván', 'email' => 'ivan@eldiario.example', 'password' => 'secret1234']);
        $writer->assignRole('reporter');
        $this->actingAs($writer, 'sanctum');

        $response = (new SnkKilkanaTagsController)->merge(Request::create('/', 'POST', ['into' => $other->id]), $one->id);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotNull(SnkKilkanaTagModel::query()->find($one->id), 'Y sigue ahí.');
    }

    private function asEditorInChief(): SnkKilkanaTagsController
    {
        $user = CoreUserModel::firstOrCreate(
            ['email' => 'marta@eldiario.example'],
            ['name' => 'Marta', 'password' => 'secret1234']
        );
        $user->assignRole('editor_in_chief');
        $this->actingAs($user, 'sanctum');

        return new SnkKilkanaTagsController;
    }

    /** `?fields=` recorta la fila y las dos cifras siguen saliendo: se cuelgan después del recorte. */
    public function test_requesting_fields_trims_the_row_and_keeps_the_counts(): void
    {
        $this->makePost('Pleno', '2026-07-01 08:00:00')->tags()->attach(SnkKilkanaTagModel::create(['name' => 'movilidad']));
        $this->app['request']->query->set('fields', 'name');

        $row = $this->tagRows()[0];

        $this->assertEqualsCanonicalizing(['id', 'name', 'posts_count', 'last_used_at'], array_keys($row));
        $this->assertSame(1, $row['posts_count']);
    }

    /** @return array<int, array<string, mixed>> */
    private function tagRows(): array
    {
        $controller = new class extends SnkKilkanaTagsController
        {
            /** @return array<int, array<string, mixed>> */
            public function rows(): array
            {
                return $this->queryBuilder()->get()->toArray();
            }
        };

        return $controller->rows();
    }

    private function makePost(string $title, string $date, string $status = 'published'): SnkKilkanaPostModel
    {
        $section = SnkKilkanaCategoryModel::firstOrCreate(
            ['slug' => 'local'],
            ['name' => 'Local', 'status' => 'live', 'position' => 0]
        );
        $byline = SnkKilkanaAuthorModel::firstOrCreate(['slug' => 'redaccion'], ['alias' => 'Redacción']);

        return SnkKilkanaPostModel::create([
            'title' => $title,
            'slug' => str($title)->slug()->value(),
            'url_date' => substr($date, 0, 10),
            'category_id' => $section->id,
            'author_id' => $byline->id,
            'status' => $status,
            'publish_at' => $date,
        ]);
    }
}
