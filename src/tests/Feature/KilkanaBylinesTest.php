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
 * Una firma es una pagina publica; un asiento es acceso al panel.
 *
 * Toda la clase gira alrededor de esa frase: hay firmas sin cuenta —agencias, colectivos— y
 * cuentas sin firma, y hasta ahora el esquema no lo admitia. Lo que se prueba aqui es lo que se
 * rompe al separarlas: de donde sale el nombre publicado cuando no hay cuenta detras, y que el
 * correo de la pagina de autor no es el de entrar.
 */
class KilkanaBylinesTest extends TestCase
{
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
    }

    /** Una agencia publica y no entra: es el caso que obliga a que la cuenta sea opcional. */
    public function test_una_firma_sin_cuenta_publica_su_alias(): void
    {
        $agencia = SnkKilkanaAuthorModel::create([
            'alias' => 'Agencia EFE',
            'kind' => SnkKilkanaAuthorKindEnum::Agency,
            'job_title' => 'Agencia de noticias',
        ]);

        $this->assertNull($agencia->user_id);
        $this->assertSame('Agencia EFE', $agencia->name, 'El nombre publicado sale del alias.');
        $this->assertSame('agencia-efe', $agencia->slug, 'Y la URL se deriva de él.');
        $this->assertNull($this->index()[0]['seat'], 'Sin cuenta no hay asiento con el que cruzar.');
    }

    /**
     * La regla que sostiene que la cuenta pueda faltar.
     *
     * El nombre publicado sale del alias o de la cuenta, así que sin ninguno de los dos la
     * firma se publica como una cadena vacía: una fila sin nombre en el panel y una página de
     * autor sin título.
     */
    public function test_una_firma_sin_cuenta_y_sin_alias_no_se_guarda(): void
    {
        $this->expectException(ValidationException::class);
        SnkKilkanaAuthorModel::create(['kind' => SnkKilkanaAuthorKindEnum::Agency]);
    }

    /** Y desvincular una firma con cuenta cae en la misma regla si no tiene alias. */
    public function test_desvincular_una_firma_sin_alias_tampoco_cuela(): void
    {
        $firma = $this->byline('Marta Beneyto');
        $this->assertSame('Marta Beneyto', $firma->name);

        $this->expectException(ValidationException::class);
        $firma->update(['user_id' => null]);
    }

    /**
     * Dos correos distintos a propósito: con el que se entra y con el que se contacta.
     *
     * Publicar el primero como si fuera el segundo es lo que hacía la ficha del panel cuando
     * el único correo de una firma era el de su cuenta.
     */
    public function test_el_correo_publico_no_es_el_de_la_cuenta(): void
    {
        $firma = $this->byline('Nuria Espí');
        $firma->update(['public_email' => 'nuria.redaccion@eldiario.example']);

        // El de acceso solo se puede pedir desde el listado del panel; el público es de la firma.
        $this->assertSame('nuria.espi@eldiario.example', $this->index()[0]['seat']);
        $this->assertSame('nuria.redaccion@eldiario.example', $firma->fresh()->public_email);
    }

    /**
     * El correo de acceso no sale del panel, y esto es lo que lo sostiene.
     *
     * Mientras `seat` fue un `append` del modelo viajaba en **cada** firma que serializara
     * cualquiera —y la portada pública y la página de cada artículo anidan la firma entera—, así
     * que el correo con el que se entra al CMS salía a la calle sin sesión. Ahora es una
     * subconsulta del listado del panel: quien no la pide, no lo tiene.
     */
    public function test_el_asiento_es_del_listado_del_panel_y_no_del_modelo(): void
    {
        $firma = $this->byline('Iván Sagredo');

        $this->assertArrayNotHasKey('seat', $firma->toArray(), 'El modelo no lo anexa.');
        $this->assertSame('ivan.sagredo@eldiario.example', $this->index()[0]['seat']);
    }

    /** Lo que el listado del panel no puede calcular por su cuenta. */
    public function test_el_listado_trae_recuento_y_ultima_publicada(): void
    {
        $firma = $this->byline('Ramón Clavel');
        $seccion = SnkKilkanaCategoryModel::create([
            'name' => 'Local', 'slug' => 'local', 'status' => 'live', 'position' => 0,
        ]);

        $this->makePost('Pleno', $seccion, $firma, 'published', '2026-07-01 08:00:00');
        $this->makePost('Presupuesto', $seccion, $firma, 'published', '2026-08-01 08:00:00');
        $this->makePost('Sin publicar', $seccion, $firma, 'draft');

        $fila = $this->index()[0];

        $this->assertSame(3, $fila['posts_count'], 'Cuenta el archivo entero, publicado o no.');
        $this->assertStringStartsWith('2026-08-01', (string) $fila['last_published_at']);
        $this->assertSame('ramon.clavel@eldiario.example', $fila['seat']);
    }

    /**
     * `?fields=` recorta la fila y **no** se lleva lo que el catálogo cuelga.
     *
     * El asiento es un `addSelect` y los otros dos son agregados; spatie hace `select()`, así
     * que puestos antes del recorte desaparecían. Y no se piden por su nombre: no son columnas.
     */
    public function test_pedir_campos_recorta_la_fila_y_conserva_el_asiento(): void
    {
        $this->byline('Ramón Clavel');
        $this->app['request']->query->set('fields', 'slug');

        $fila = $this->index()[0];

        $this->assertEqualsCanonicalizing(
            // `id` no se pidió y viaja: la precarga de las URLs retiradas lo necesita. `name` es
            // el apendado del modelo, y `user_id` se selecciona para la cuenta pero no sale.
            ['id', 'slug', 'name', 'seat', 'posts_count', 'last_published_at', 'retired_slugs'],
            array_keys($fila),
        );
        $this->assertSame('ramon.clavel@eldiario.example', $fila['seat']);
    }

    public function test_un_campo_fuera_del_techo_no_se_sirve(): void
    {
        $this->app['request']->query->set('fields', 'user_id,created_by');

        $this->expectException(InvalidFieldQuery::class);
        $this->index();
    }

    /** El listado tal como sale por la API, sin pasar por HTTP: lo que se prueba es la consulta. */
    private function index(): array
    {
        $controlador = new class extends SnkKilkanaAuthorsController
        {
            public function listado(): array
            {
                return $this->queryBuilder()->get()->toArray();
            }
        };

        return $controlador->listado();
    }

    private function byline(string $nombre): SnkKilkanaAuthorModel
    {
        $user = CoreUserModel::create([
            'name' => $nombre,
            'email' => str()->slug($nombre, '.').'@eldiario.example',
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
