<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Enums\SnkKilkanaAuthorKindEnum;
use Packages\Kilkana\Http\Controllers\SnkKilkanaAuthorsController;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Spatie\QueryBuilder\Exceptions\InvalidFieldQuery;
use Tests\TestCase;

/**
 * Una firma es una página pública; un asiento es acceso al panel.
 *
 * Toda la clase gira alrededor de esa frase: hay firmas sin cuenta —agencias, colectivos— y
 * cuentas sin firma, y el esquema admite las dos. Lo que se prueba aquí es lo que se rompe al
 * separarlas: de dónde sale el nombre publicado cuando no hay cuenta detrás, y que el correo de
 * la página de autor no es el de entrar.
 */
class KilkanaBylinesTest extends TestCase
{
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
    }

    /** Una agencia publica y no entra: es el caso que obliga a que la cuenta sea opcional. */
    public function test_a_byline_without_an_account_publishes_its_alias(): void
    {
        $agency = SnkKilkanaAuthorModel::create([
            'alias' => 'Agencia EFE',
            'kind' => SnkKilkanaAuthorKindEnum::Agency,
            'job_title' => 'Agencia de noticias',
        ]);

        $this->assertNull($agency->user_id);
        $this->assertSame('Agencia EFE', $agency->name, 'El nombre publicado sale del alias.');
        $this->assertSame('agencia-efe', $agency->slug, 'Y la URL se deriva de él.');
        $this->assertNull($this->index()[0]['seat'], 'Sin cuenta no hay asiento con el que cruzar.');
    }

    /** La URL sale del nombre completo, y un homónimo recibe un sufijo en vez de chocar. */
    public function test_the_url_comes_from_the_full_name_and_two_namesakes_do_not_clash(): void
    {
        $first = $this->byline('Jorge Bowen');
        $namesake = CoreUserModel::create(['name' => 'Jorge', 'lastname' => 'Bowen López', 'email' => 'jorge.2@eldiario.example', 'password' => 'secret1234']);
        $second = SnkKilkanaAuthorModel::create(['user_id' => $namesake->id]);

        $this->assertSame('jorge-bowen-lopez', $first->slug);
        $this->assertMatchesRegularExpression('/^jorge-bowen-lopez-\\d{4}$/', $second->slug);
    }

    /**
     * La regla que sostiene que la cuenta pueda faltar.
     *
     * El nombre publicado sale del alias o de la cuenta, así que sin ninguno de los dos la
     * firma se publica como una cadena vacía: una fila sin nombre en el panel y una página de
     * autor sin título.
     */
    public function test_a_byline_without_account_or_alias_is_not_saved(): void
    {
        $this->expectException(ValidationException::class);
        SnkKilkanaAuthorModel::create(['kind' => SnkKilkanaAuthorKindEnum::Agency]);
    }

    /** Y desvincular una firma con cuenta cae en la misma regla si no tiene alias. */
    public function test_unlinking_a_byline_without_an_alias_is_rejected_too(): void
    {
        $byline = $this->byline('Marta Beneyto');
        $this->assertSame('Marta Beneyto López', $byline->name);

        $this->expectException(ValidationException::class);
        $byline->update(['user_id' => null]);
    }

    /**
     * Dos correos distintos a propósito: con el que se entra y con el que se contacta.
     *
     * Si el único correo de una firma fuera el de su cuenta, la ficha del panel publicaría el
     * primero como si fuera el segundo.
     */
    public function test_the_public_email_is_not_the_account_email(): void
    {
        $byline = $this->byline('Nuria Espí');
        $byline->update(['public_email' => 'nuria.redaccion@eldiario.example']);

        // El de acceso solo se puede pedir desde el listado del panel; el público es de la firma.
        $this->assertSame('nuria.espi@eldiario.example', $this->index()[0]['seat']);
        $this->assertSame('nuria.redaccion@eldiario.example', $byline->fresh()->public_email);
    }

    /**
     * El correo de acceso no sale del panel, y esto es lo que lo sostiene.
     *
     * `seat` es una subconsulta del listado del panel y no un `append` del modelo. Como `append`
     * viajaría en **cada** firma que serializara cualquiera —y la portada pública y la página de
     * cada artículo anidan la firma entera—, y el correo con el que se entra al CMS saldría a la
     * calle sin sesión. Quien no pide el listado, no lo tiene.
     */
    public function test_the_seat_belongs_to_the_panel_listing_and_not_the_model(): void
    {
        $byline = $this->byline('Iván Sagredo');

        $this->assertArrayNotHasKey('seat', $byline->toArray(), 'El modelo no lo anexa.');
        $this->assertSame('ivan.sagredo@eldiario.example', $this->index()[0]['seat']);
    }

    /** Lo que el listado del panel no puede calcular por su cuenta. */
    public function test_the_list_includes_the_count_and_last_published(): void
    {
        $byline = $this->byline('Ramón Clavel');
        $section = SnkKilkanaCategoryModel::create([
            'name' => 'Local', 'slug' => 'local', 'status' => 'live', 'position' => 0,
        ]);

        $this->makePost('Pleno', $section, $byline, 'published', '2026-07-01 08:00:00');
        $this->makePost('Presupuesto', $section, $byline, 'published', '2026-08-01 08:00:00');
        $this->makePost('Sin publicar', $section, $byline, 'draft');

        $row = $this->index()[0];

        $this->assertSame(3, $row['posts_count'], 'Cuenta el archivo entero, publicado o no.');
        $this->assertStringStartsWith('2026-08-01', (string) $row['last_published_at']);
        $this->assertSame('ramon.clavel@eldiario.example', $row['seat']);
    }

    /**
     * `?fields=` recorta la fila y **no** se lleva lo que el catálogo cuelga.
     *
     * El asiento es un `addSelect` y los otros dos son agregados; spatie hace `select()`, así
     * que puestos antes del recorte desaparecen. Y no se piden por su nombre: no son columnas.
     */
    public function test_requesting_fields_trims_the_row_and_keeps_the_seat(): void
    {
        $this->byline('Ramón Clavel');
        $this->app['request']->query->set('fields', 'slug');

        $row = $this->index()[0];

        $this->assertEqualsCanonicalizing(
            // `id` no se pidió y viaja: la precarga de las URLs retiradas lo necesita. `name` es
            // el apendado del modelo, y `user_id` se selecciona para la cuenta pero no sale.
            ['id', 'slug', 'name', 'seat', 'posts_count', 'last_published_at', 'retired_slugs'],
            array_keys($row),
        );
        $this->assertSame('ramon.clavel@eldiario.example', $row['seat']);
    }

    public function test_a_field_outside_the_allowlist_is_not_served(): void
    {
        $this->app['request']->query->set('fields', 'user_id,created_by');

        $this->expectException(InvalidFieldQuery::class);
        $this->index();
    }

    /** El listado tal como sale por la API, sin pasar por HTTP: lo que se prueba es la consulta. */
    private function index(): array
    {
        $controller = new class extends SnkKilkanaAuthorsController
        {
            public function rows(): array
            {
                return $this->queryBuilder()->get()->toArray();
            }
        };

        return $controller->rows();
    }

    private function byline(string $name): SnkKilkanaAuthorModel
    {
        $user = CoreUserModel::create([
            'name' => $name,
            'lastname' => 'López',
            'email' => str()->slug($name, '.').'@eldiario.example',
            'password' => 'secret1234',
        ]);

        return SnkKilkanaAuthorModel::create(['user_id' => $user->id]);
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
}
