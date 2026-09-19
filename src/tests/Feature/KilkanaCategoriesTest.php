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
 * La sección retirada del menú, y lo que el listado del panel necesita saber de cada una.
 *
 * `hidden` no es un tercer sinónimo de «no publicada»: es el único estado con el que una
 * redacción deja de ofrecer una sección **sin romper lo que ya está enlazado**. De ahí que
 * cada prueba de aquí mire una de las dos mitades — sigue abriéndose, deja de anunciarse — y
 * que las dos estén separadas: juntarlas es exactamente lo que hace `draft`.
 */
class KilkanaCategoriesTest extends TestCase
{
    private SnkKilkanaPublicService $street;

    protected function setUp(): void
    {
        parent::setUp();

        // `tenant` es sqlite `:memory:` en pruebas (`phpunit.xml`), así que la base vive
        // mientras viva la conexión: basta con migrar encima y no hay nada que limpiar.
        DB::setDefaultConnection('tenant');
        foreach (['Core', 'Kilkana'] as $package) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$package}/database/migrations",
            ])->run();
        }

        $this->street = app(SnkKilkanaPublicService::class);
    }

    public function test_a_hidden_section_opens_by_url_and_stays_out_of_the_menu(): void
    {
        $this->category('Motor', 'motor', 'live');
        $hidden = $this->category('Sucesos', 'sucesos', 'hidden');

        $menu = $this->street->categories()->pluck('slug')->all();
        $this->assertSame(['motor'], $menu, 'Una sección oculta no se ofrece en la navegación.');

        $opened = $this->street->category('sucesos');
        $this->assertInstanceOf(SnkKilkanaCategoryModel::class, $opened);
        $this->assertSame($hidden->id, $opened->id, 'Su URL sigue respondiendo: es su definición.');
    }

    /**
     * Las dos formas de dejar de ofrecer una sección con archivo, y solo una es legítima.
     *
     * `draft` no admite piezas publicadas —sus URLs dejarían de responder—; `hidden` es la
     * salida que no rompe nada.
     */
    public function test_a_section_with_published_posts_can_be_hidden_but_not_moved_back_to_draft(): void
    {
        $section = $this->category('Cultura', 'cultura', 'live');
        $this->makePost('Estreno', $section, $this->author('Ada Prat'), 'published', '2026-08-01 08:00:00');

        $section->update(['status' => SnkKilkanaCategoryStatusEnum::Hidden]);
        $this->assertSame(SnkKilkanaCategoryStatusEnum::Hidden, $section->fresh()->status);

        $this->expectException(ValidationException::class);
        $section->update(['status' => SnkKilkanaCategoryStatusEnum::Draft]);
    }

    /** El estado es cocina de la redacción: la calle no tiene por qué saber qué se retiró. */
    public function test_the_street_does_not_expose_a_section_status(): void
    {
        $this->category('Motor', 'motor', 'live');
        $this->category('Agenda', 'agenda', 'hidden');

        $menu = $this->street->categories()->first()->toArray();
        $hidden = $this->street->category('agenda');

        $this->assertArrayNotHasKey('status', $menu);
        $this->assertArrayNotHasKey('position', $menu);
        $this->assertInstanceOf(SnkKilkanaCategoryModel::class, $hidden);
        $this->assertArrayNotHasKey('status', $hidden->toArray());
    }

    /** Publicar en una oculta sí: el lector puede abrir la pieza y la sección que la firma. */
    public function test_posts_can_be_published_in_a_hidden_section_but_not_in_a_draft_one(): void
    {
        $hidden = $this->category('Opinión', 'opinion', 'hidden');
        $draft = $this->category('Podcast', 'podcast', 'draft');
        $byline = $this->author('Ada Prat');

        $this->makePost('Columna', $hidden, $byline, 'published', '2026-08-02 08:00:00');
        $this->assertSame(1, SnkKilkanaPostModel::query()->where('category_id', $hidden->id)->count());

        $this->expectException(ValidationException::class);
        $this->makePost('Piloto', $draft, $byline, 'published', '2026-08-02 09:00:00');
    }

    /**
     * El muro mira el árbol entero y no solo lo que cuelga directo.
     *
     * Sin eso, una madre sin piezas propias y con una hija publicando pasa a preparación sin que
     * nada la pare: la hija sigue alcanzable, sus piezas públicas, y la página de la madre
     * responde 404. Se llega ahí en cuatro pasos desde el panel.
     */
    public function test_a_parent_cannot_go_back_to_draft_while_a_subsection_has_published_posts(): void
    {
        $parent = $this->category('Deportes', 'deportes', 'live');
        $child = SnkKilkanaCategoryModel::create([
            'name' => 'Futbol',
            'slug' => 'futbol',
            'status' => 'live',
            'position' => 0,
            'parent_id' => $parent->id,
        ]);
        $this->makePost('Derbi', $child, $this->author('Ada Prat'), 'published', '2026-08-03 08:00:00');

        // La madre no archiva ni una pieza suya.
        $this->assertSame(0, SnkKilkanaPostModel::query()->where('category_id', $parent->id)->count());

        $this->expectException(ValidationException::class);
        $parent->update(['status' => SnkKilkanaCategoryStatusEnum::Draft]);
    }

    /**
     * La puerta que `guardStatus` no cubre: la jerarquía.
     *
     * Cerrar solo el estado deja llegar al mismo sitio prohibido por el otro lado —mover una
     * sección viva bajo una madre en preparación—, y ahí la madre responde 404 mientras su
     * hija y las piezas de su hija siguen abriéndose.
     */
    public function test_a_section_cannot_be_nested_under_a_less_visible_parent(): void
    {
        $draft = $this->category('Medio ambiente', 'medio-ambiente', 'draft');
        $live = $this->category('Politica', 'politica', 'live');

        $this->expectException(ValidationException::class);
        $live->update(['parent_id' => $draft->id]);
    }

    /** Y `hidden` cuenta como menos visible: el menú de una hija cuelga del de su madre. */
    public function test_a_live_section_cannot_be_nested_under_a_hidden_parent(): void
    {
        $hidden = $this->category('Cultura', 'cultura', 'hidden');
        $live = $this->category('Agenda', 'agenda', 'live');

        // Al revés sí: una oculta bajo una viva es exactamente lo que `hidden` significa.
        $inverted = $this->category('Sucesos', 'sucesos', 'hidden');
        $parent = $this->category('Local', 'local', 'live');
        $inverted->update(['parent_id' => $parent->id]);
        $this->assertSame($parent->id, $inverted->fresh()->parent_id);

        $this->expectException(ValidationException::class);
        $live->update(['parent_id' => $hidden->id]);
    }

    /** Y sin madre que mirar no se pasa: la puerta falla cerrada, como el resto. */
    public function test_a_parent_that_does_not_exist_is_rejected(): void
    {
        $section = $this->category('Agenda', 'agenda', 'draft');

        $this->expectException(ValidationException::class);
        // `guardTree` no lo caza: su consulta devuelve null igual que la de una raíz.
        $section->update(['parent_id' => 9999]);
    }

    /** Y la misma regla mirando hacia abajo: bajar el estado de una madre con hijas más visibles. */
    public function test_a_parent_cannot_drop_below_the_status_of_its_children(): void
    {
        $parent = $this->category('Deportes', 'deportes', 'live');
        SnkKilkanaCategoryModel::create([
            'name' => 'Futbol',
            'slug' => 'futbol',
            'status' => 'live',
            'position' => 0,
            'parent_id' => $parent->id,
        ]);

        $this->expectException(ValidationException::class);
        $parent->update(['status' => SnkKilkanaCategoryStatusEnum::Hidden]);
    }

    /**
     * Lo que el listado del panel no puede calcular por su cuenta.
     *
     * Las tres columnas cuestan una subconsulta por fila; la alternativa es traerse el
     * archivo entero al cliente y contarlo allí. `last_published_at` mira solo lo publicado:
     * un borrador de hoy no es actividad de la sección.
     */
    public function test_the_list_includes_count_last_published_and_menu_order(): void
    {
        $culture = $this->category('Cultura', 'cultura', 'live', 1);
        $local = $this->category('Local', 'local', 'live', 0);
        $byline = $this->author('Ada Prat');

        $this->makePost('Vieja', $local, $byline, 'published', '2026-07-01 08:00:00');
        $this->makePost('Nueva', $local, $byline, 'published', '2026-08-01 08:00:00');
        $this->makePost('Sin publicar', $local, $byline, 'draft');

        $rows = $this->index();

        $this->assertSame(['local', 'cultura'], array_column($rows, 'slug'), 'Ordena por posición.');
        $this->assertSame(3, $rows[0]['posts_count'], 'Cuenta el archivo entero, publicado o no.');
        $this->assertStringStartsWith('2026-08-01', (string) $rows[0]['last_published_at']);
        $this->assertNull($rows[1]['last_published_at'], 'Una sección sin nada publicado no tiene fecha.');
        $this->assertSame($culture->id, $rows[1]['id']);
    }

    /** La URL que se deja atrás viaja con la sección: es lo que el panel enseña como 301. */
    public function test_the_list_includes_the_urls_the_section_left_behind(): void
    {
        $section = $this->category('Tecnología', 'tecnologia', 'live');
        $section->update(['slug' => 'tech']);

        $rows = $this->index();

        $this->assertSame('tech', $rows[0]['slug']);
        $this->assertSame(['tecnologia'], array_column($rows[0]['retired_slugs'], 'path'));
    }

    /** `?fields=` recorta la fila; los agregados y las URLs retiradas salen igual, colgados después. */
    public function test_requesting_fields_trims_the_row_and_keeps_the_aggregates(): void
    {
        $this->category('Local', 'local', 'live');
        $this->app['request']->query->set('fields', 'slug');

        $row = $this->index()[0];

        $this->assertEqualsCanonicalizing(['id', 'slug', 'posts_count', 'last_published_at', 'retired_slugs'], array_keys($row));
        $this->assertSame(0, $row['posts_count']);
    }

    /** El listado tal como sale por la API, sin pasar por HTTP: lo que se prueba es la consulta. */
    private function index(): array
    {
        $controller = new class extends SnkKilkanaCategoriesController
        {
            public function rows(): array
            {
                return $this->queryBuilder()->get()->toArray();
            }
        };

        return $controller->rows();
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

    public function test_a_section_cannot_be_named_after_a_fixed_street_route(): void
    {
        foreach ([['name' => 'Subscribe'], ['name' => 'Cuentas', 'slug' => 'admin']] as $data) {
            try {
                SnkKilkanaCategoryModel::create($data + ['status' => 'live', 'position' => 0]);
                $this->fail('Se guardó una sección cuya página pública no existirá nunca.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('slug', $e->errors());
            }
        }
        $this->assertSame(0, SnkKilkanaCategoryModel::query()->count());
    }

    /** Un slug dado a mano tiene que tener forma de slug: con punto, barra o mayúscula no casa con ninguna ruta. */
    public function test_a_hand_typed_slug_that_is_not_slug_shaped_is_rejected(): void
    {
        app(SnkKilkanaRoleCatalogService::class)->sync();
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('editor_in_chief');
        $this->actingAs($marta, 'sanctum');
        $create = fn (string $slug) => app(SnkKilkanaCategoriesController::class)->store(Request::create('/', 'POST', ['name' => 'Prueba', 'slug' => $slug]));

        foreach (['robots.txt', 'con/barra', 'Mayus', 'con espacio', '-borde-'] as $slug) {
            $this->assertSame(422, $create($slug)->getStatusCode(), $slug);
        }
        $this->assertSame(201, $create('con-guion-2')->getStatusCode());
    }
}
