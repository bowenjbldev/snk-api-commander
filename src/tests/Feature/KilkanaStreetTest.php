<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Kilkana\Http\Resources\SnkKilkanaPostResource;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaPostViewModel;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Kilkana\Services\SnkKilkanaPublicService;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Spatie\QueryBuilder\Exceptions\InvalidFieldQuery;
use Tests\TestCase;

/**
 * Lo que la calle necesita del periódico y no puede pedir de otra manera: la cabecera del
 * sitio, y las fotos del cuerpo de una pieza resueltas, porque la mediateca es del panel.
 */
class KilkanaStreetTest extends TestCase
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

    public function test_the_site_includes_the_masthead_even_without_a_theme(): void
    {
        SnkKilkanaSettingModel::create(['id' => 1, 'tenant_name' => 'El Diario', 'tenant_tagline' => 'La comarca, contada cada día']);

        $site = app(SnkKilkanaPublicService::class)->site();

        $this->assertSame('El Diario', $site['tenant']['name']);
        $this->assertSame('La comarca, contada cada día', $site['tenant']['tagline']);
        $this->assertNull($site['theme']);
    }

    public function test_a_public_post_includes_its_body_photos_resolved(): void
    {
        $section = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $byline = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        $photo = SnkKilkanaMediaModel::create(['url' => '/storage/a.jpg', 'url_hash' => sha1('a'), 'alt' => 'La plaza', 'caption' => 'Ayer', 'credit' => 'E. D.', 'width' => 1600, 'height' => 900]);
        SnkKilkanaPostModel::create([
            'title' => 'Plan', 'slug' => 'plan', 'url_date' => '2026-07-01', 'category_id' => $section->id, 'author_id' => $byline->id,
            'status' => 'published', 'publish_at' => '2026-07-01 08:00:00', 'excerpt' => 'Entradilla.',
            'body_blocks' => [
                ['id' => 'p1', 'type' => 'paragraph', 'config' => ['text' => 'Hola']],
                ['id' => 'i1', 'type' => 'image', 'config' => ['media_id' => $photo->id, 'caption' => 'Ayer']],
                ['id' => 'i2', 'type' => 'image', 'config' => ['media_id' => 999]],
            ],
        ]);

        $post = app(SnkKilkanaPublicService::class)->post('2026', '07', '01', 'plan')->toArray();

        $this->assertSame([[
            'id' => (string) $photo->id, 'url' => '/storage/a.jpg', 'alt' => 'La plaza', 'caption' => 'Ayer', 'credit' => 'E. D.', 'width' => 1600, 'height' => 900, 'variants' => null,
        ]], $post['media'], 'Solo las que existen, y con lo que la calle pinta.');
        $this->assertSame('image', $post['body_blocks'][1]['type']);
    }

    /** «Lo más leído» se ordena por lecturas, y una lectura es un `read`: si eso revienta, la cifra es cero para siempre. */
    public function test_a_read_counts_and_the_same_visitor_is_a_single_reader(): void
    {
        $section = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $byline = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        $post = SnkKilkanaPostModel::create([
            'title' => 'Plan', 'slug' => 'plan', 'url_date' => '2026-07-01', 'category_id' => $section->id, 'author_id' => $byline->id,
            'status' => 'published', 'publish_at' => '2026-07-01 08:00:00', 'excerpt' => 'Entradilla.', 'body_blocks' => [],
        ]);
        $visitor = Request::create('/', 'POST', server: ['REMOTE_ADDR' => '10.0.0.7', 'HTTP_USER_AGENT' => 'lector']);
        $service = app(SnkKilkanaPublicService::class);

        $this->assertTrue($service->recordView('2026', '07', '01', 'plan', 'open', $visitor));
        $this->assertTrue($service->recordView('2026', '07', '01', 'plan', 'read', $visitor));
        $this->assertTrue($service->recordView('2026', '07', '01', 'plan', 'read', $visitor));
        $this->assertFalse($service->recordView('2026', '07', '01', 'nadie', 'read', $visitor));

        $today = SnkKilkanaPostViewModel::query()->where('post_id', $post->id)->where('date', now()->toDateString())->first();
        $this->assertSame(1, (int) $today->opens);
        $this->assertSame(2, (int) $today->engaged);
        $this->assertSame(1, (int) $today->readers, 'Dos lecturas del mismo visitante en el día son un lector.');
    }

    /** La hemeroteca es la misma lista más atrás, y el sitemap solo lleva lo publicado e indexable. */
    public function test_the_public_list_paginates_and_the_sitemap_carries_what_is_indexable(): void
    {
        $section = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $byline = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        foreach ([['a', '2026-07-03', []], ['b', '2026-07-02', []], ['c', '2026-07-01', ['noindex' => true]], ['d', '2026-06-30', ['status' => 'draft']]] as [$slug, $day, $extra]) {
            SnkKilkanaPostModel::create($extra + [
                'title' => $slug, 'slug' => $slug, 'url_date' => $day, 'category_id' => $section->id, 'author_id' => $byline->id,
                'status' => 'published', 'publish_at' => "{$day} 08:00:00", 'excerpt' => 'E.', 'body_blocks' => [],
            ]);
        }
        $service = app(SnkKilkanaPublicService::class);

        $this->assertSame(['a', 'b'], $service->posts(['per_page' => 2])->pluck('slug')->all());
        $this->assertSame(['c'], $service->posts(['per_page' => 2, 'page' => 2])->pluck('slug')->all());
        $this->assertSame([], $service->posts(['per_page' => 2, 'page' => 3])->pluck('slug')->all());

        $this->assertSame(['/2026/07/03/a', '/2026/07/02/b'], array_column($service->sitemap(), 'path'));
    }

    /** Una etiqueta en la calle: su rótulo y sus piezas, y nada si no existe. */
    public function test_the_public_list_filters_by_tag_and_labels_the_tag(): void
    {
        $section = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $byline = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        $drought = SnkKilkanaTagModel::create(['name' => 'Sequía', 'slug' => 'sequia']);
        foreach (['a', 'b'] as $slug) {
            $post = SnkKilkanaPostModel::create([
                'title' => $slug, 'slug' => $slug, 'url_date' => '2026-07-01', 'category_id' => $section->id, 'author_id' => $byline->id,
                'status' => 'published', 'publish_at' => '2026-07-01 08:00:00', 'excerpt' => 'E.', 'body_blocks' => [],
            ]);
            if ($slug === 'a') {
                $post->tags()->attach($drought->id);
            }
        }
        $service = app(SnkKilkanaPublicService::class);

        $this->assertSame(['a'], $service->posts(['tag' => 'sequia'])->pluck('slug')->all());
        $this->assertSame(['name' => 'Sequía', 'slug' => 'sequia'], $service->tag('sequia'));
        $this->assertNull($service->tag('nada'));
    }

    /**
     * El listado de la calle no paga lo que no enseña.
     *
     * `makeHidden('body_blocks')` esconde el cuerpo **al serializar**, después de haberlo
     * traído: una portada de cien piezas mueve 239 KB de cuerpos de MariaDB a PHP para
     * tirarlos al final. Y `views_day`/`views_week` son dos subconsultas correlacionadas por
     * fila que no lee nadie, ni la calle ni el panel.
     *
     * Se comprueba sobre el SQL **y** sobre la fila, porque son dos fallos distintos: el
     * primero no se ve en la respuesta —el JSON sale igual con y sin el cuerpo— y el segundo
     * sí.
     */
    public function test_the_public_listing_skips_the_body_and_what_nobody_renders(): void
    {
        $this->posts(['a']);
        // Lo que pide el frontend: sin `?fields=` la puerta sirve su techo, y las cuatro
        // relaciones se incluyen por su nombre.
        request()->query->replace(['include' => 'category,author,tags,coverMedia']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $post = app(SnkKilkanaPublicService::class)->posts(['per_page' => 5])->first();
        $sql = DB::getQueryLog()[0]['query'];
        DB::disableQueryLog();
        $row = json_decode(json_encode(new SnkKilkanaPostResource($post)), true);

        $this->assertStringNotContainsString('body_blocks', $sql, 'El listado se trae el cuerpo entero de cada pieza.');
        $this->assertStringNotContainsString('views_day', $sql);
        $this->assertStringNotContainsString('views_week', $sql);
        $this->assertStringContainsString('views_total', $sql);

        // Lo que la calle lee de una pieza. La lista está escrita a mano: si alguien recorta el
        // techo de más, esto se pone rojo antes de que el periódico pierda una portada.
        foreach (['id', 'title', 'excerpt', 'slug', 'status', 'content_tier', 'publish_at', 'unpublish_at',
            'updated_at', 'url_path', 'views_total', 'comments_count', 'cover', 'category', 'author', 'tags',
            'noindex', 'seo_title', 'seo_description', 'og_title', 'og_description', 'og_image_id'] as $field) {
            $this->assertArrayHasKey($field, $row, "«{$field}» lo lee la calle y ha dejado de viajar.");
        }
        foreach (['body_blocks', 'created_by', 'updated_by', 'deleted_by', 'deleted_at',
            'canonical_url', 'featured', 'views_day', 'views_week'] as $field) {
            $this->assertArrayNotHasKey($field, $row, "«{$field}» es de dentro y no pinta nada en la calle.");
        }
    }

    /**
     * El sitemap devuelve dos campos por pieza, y eso es lo que tiene que costar.
     *
     * Un `get(['url_path','updated_at'])` no basta: un agregado o una relación que el modelo
     * cargue solo fija `kilkana_posts.*` en cuanto se construye la consulta, y trae el archivo
     * publicado entero —cuerpos incluidos—. Y `url_path` ni siquiera es una columna, es un
     * accesor: proyectarlo por su nombre es un «Unknown column».
     */
    public function test_the_sitemap_loads_no_relations_or_columns_it_does_not_return(): void
    {
        $this->posts(['a', 'b']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $sitemap = app(SnkKilkanaPublicService::class)->sitemap();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries, 'El sitemap carga relaciones que no devuelve.');
        $this->assertStringNotContainsString('body_blocks', $queries[0]['query']);
        $this->assertSame(['path', 'updated_at'], array_keys($sitemap[0]));
    }

    /**
     * Dos piezas del mismo minuto salen siempre en el mismo orden.
     *
     * Sin desempate, `order by publish_at desc` no es un orden total y la base puede devolver
     * los empates como quiera —basta con que cambie el plan, y recortar columnas lo cambia—. Esta
     * lista se pagina con `forPage`, así que un orden inestable hace que la página 2 repita
     * una pieza y se salte otra.
     */
    public function test_two_posts_from_the_same_minute_always_appear_in_the_same_order(): void
    {
        $this->posts(['a', 'b', 'c'], '2026-07-01 08:00:00');
        $service = app(SnkKilkanaPublicService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $firstPage = $service->posts(['per_page' => 2])->pluck('slug')->all();
        $sql = DB::getQueryLog()[0]['query'];
        DB::disableQueryLog();
        $secondPage = $service->posts(['per_page' => 2, 'page' => 2])->pluck('slug')->all();

        // Sobre el SQL y no solo sobre el resultado: con tres filas en sqlite los empates salen
        // estables por casualidad, así que comprobar el orden devuelto no fija nada. Lo que hay
        // que fijar es que el desempate esté escrito.
        $this->assertMatchesRegularExpression(
            '/order by .*publish_at.* desc, .*id.* desc/',
            $sql,
            'El listado no desempata, así que su orden lo decide el plan de la consulta.',
        );

        $this->assertCount(2, $firstPage);
        $this->assertSame([], array_intersect($firstPage, $secondPage), 'La página 2 repite una pieza de la página 1.');
        $this->assertSame($firstPage, $service->posts(['per_page' => 2])->pluck('slug')->all(), 'La misma consulta da otro orden.');
    }

    /**
     * Quien llama elige los campos, dentro del techo del servicio.
     *
     * Y elige de verdad: `url_path` no es una columna sino un accesor sobre `url_date` y
     * `slug`, así que pedirlo tiene que traer las dos o devolvería `null` sin decir por qué
     * —la misma trampa que el sitemap—. Lo que no está en el techo se ignora, como el resto
     * de la query: nunca un 422, y nunca una columna que la calle no puede ver.
     */
    public function test_the_street_picks_fields_and_cannot_request_ones_that_are_not_its_own(): void
    {
        $this->posts(['una', 'otra']);
        $row = function (string $fields) {
            request()->query->replace($fields === '' ? [] : ['fields' => $fields]);

            return json_decode(json_encode(new SnkKilkanaPostResource(
                app(SnkKilkanaPublicService::class)->posts([])->first(),
            )), true);
        };

        // Sin pedir nada, el techo: la lista blanca de la puerta, y **no la fila entera**.
        // El paquete, cuando nadie recorta, selecciona todo; en una puerta anónima eso sería
        // servir `body_blocks` con su muro de pago dentro a quien pida `/public/posts` a secas.
        $complete = $row('');
        $this->assertArrayHasKey('seo_title', $complete);
        $this->assertArrayNotHasKey('body_blocks', $complete, 'El cuerpo no sale de aquí ni por defecto.');

        // Y elige de verdad: `url_path` no es una columna sino un derivado de `url_date` y
        // `slug`, así que se pide su columna y el Resource lo compone.
        $minimal = $row('id,title,url_date,slug');

        $this->assertSame(
            ['id', 'title', 'slug', 'views_total', 'url_path', 'comments_count'],
            array_keys($minimal),
            'Ni una relación: el modelo no las carga solo y aquí no se ha incluido ninguna.',
        );
        $this->assertNotNull($minimal['url_path'], 'El derivado sale con la columna de la que depende.');

        // Cuatro columnas pedidas, `url_path` como derivado de `url_date`, y los dos agregados
        // que esta puerta cuelga siempre. Nada más.
        foreach (['excerpt', 'seo_title', 'og_title', 'publish_at', 'noindex'] as $notRequested) {
            $this->assertArrayNotHasKey($notRequested, $minimal, "`{$notRequested}` no se pidió: no viaja.");
        }

        // El techo: lo que no es suyo lanza, que es lo único que `?fields=` no perdona —no
        // tiene bandera para ignorarlo— y aquí es lo correcto: pedir `created_by` por su
        // nombre no puede devolver la lista como si tal cosa.
        request()->query->replace(['fields' => 'id,created_by']);
        $this->expectException(InvalidFieldQuery::class);
        app(SnkKilkanaPublicService::class)->posts([]);
    }

    /**
     * Piezas publicadas con cuerpo, para medir lo que cuesta traerlo.
     *
     * @param  list<string>  $slugs
     */
    private function posts(array $slugs, ?string $at = null): void
    {
        $section = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $byline = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);

        foreach ($slugs as $i => $slug) {
            SnkKilkanaPostModel::create([
                'title' => $slug, 'slug' => $slug, 'url_date' => '2026-07-01',
                'category_id' => $section->id, 'author_id' => $byline->id, 'status' => 'published',
                'publish_at' => $at ?? '2026-07-0'.($i + 1).' 08:00:00', 'excerpt' => 'E.',
                'body_blocks' => [['id' => 'p1', 'type' => 'paragraph', 'config' => ['text' => str_repeat('x', 500)]]],
            ]);
        }
    }
}
