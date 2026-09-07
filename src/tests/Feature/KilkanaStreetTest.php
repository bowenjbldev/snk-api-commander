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
        foreach (['Core', 'Kilkana'] as $paquete) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$paquete}/database/migrations",
            ])->run();
        }

        app(SnkKilkanaRoleCatalogService::class)->sync();
    }

    public function test_el_sitio_trae_la_cabecera_aunque_no_haya_tema(): void
    {
        SnkKilkanaSettingModel::create(['id' => 1, 'tenant_name' => 'El Diario', 'tenant_tagline' => 'La comarca, contada cada día']);

        $sitio = app(SnkKilkanaPublicService::class)->site();

        $this->assertSame('El Diario', $sitio['tenant']['name']);
        $this->assertSame('La comarca, contada cada día', $sitio['tenant']['tagline']);
        $this->assertNull($sitio['theme']);
    }

    public function test_una_pieza_publica_trae_las_fotos_de_su_cuerpo_resueltas(): void
    {
        $seccion = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $firma = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        $foto = SnkKilkanaMediaModel::create(['url' => '/storage/a.jpg', 'url_hash' => sha1('a'), 'alt' => 'La plaza', 'caption' => 'Ayer', 'credit' => 'E. D.', 'width' => 1600, 'height' => 900]);
        SnkKilkanaPostModel::create([
            'title' => 'Plan', 'slug' => 'plan', 'url_date' => '2026-07-01', 'category_id' => $seccion->id, 'author_id' => $firma->id,
            'status' => 'published', 'publish_at' => '2026-07-01 08:00:00', 'excerpt' => 'Entradilla.',
            'body_blocks' => [
                ['id' => 'p1', 'type' => 'paragraph', 'config' => ['text' => 'Hola']],
                ['id' => 'i1', 'type' => 'image', 'config' => ['media_id' => $foto->id, 'caption' => 'Ayer']],
                ['id' => 'i2', 'type' => 'image', 'config' => ['media_id' => 999]],
            ],
        ]);

        $pieza = app(SnkKilkanaPublicService::class)->post('2026', '07', '01', 'plan')->toArray();

        $this->assertSame([[
            'id' => (string) $foto->id, 'url' => '/storage/a.jpg', 'alt' => 'La plaza', 'caption' => 'Ayer', 'credit' => 'E. D.', 'width' => 1600, 'height' => 900, 'variants' => null,
        ]], $pieza['media'], 'Solo las que existen, y con lo que la calle pinta.');
        $this->assertSame('image', $pieza['body_blocks'][1]['type']);
    }

    /** «Lo más leído» se ordena por lecturas, y una lectura es un `read`: si eso revienta, la cifra es cero para siempre. */
    public function test_una_lectura_cuenta_y_el_mismo_visitante_es_un_solo_lector(): void
    {
        $seccion = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $firma = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        $pieza = SnkKilkanaPostModel::create([
            'title' => 'Plan', 'slug' => 'plan', 'url_date' => '2026-07-01', 'category_id' => $seccion->id, 'author_id' => $firma->id,
            'status' => 'published', 'publish_at' => '2026-07-01 08:00:00', 'excerpt' => 'Entradilla.', 'body_blocks' => [],
        ]);
        $visitante = Request::create('/', 'POST', server: ['REMOTE_ADDR' => '10.0.0.7', 'HTTP_USER_AGENT' => 'lector']);
        $servicio = app(SnkKilkanaPublicService::class);

        $this->assertTrue($servicio->recordView('2026', '07', '01', 'plan', 'open', $visitante));
        $this->assertTrue($servicio->recordView('2026', '07', '01', 'plan', 'read', $visitante));
        $this->assertTrue($servicio->recordView('2026', '07', '01', 'plan', 'read', $visitante));
        $this->assertFalse($servicio->recordView('2026', '07', '01', 'nadie', 'read', $visitante));

        $hoy = SnkKilkanaPostViewModel::query()->where('post_id', $pieza->id)->where('date', now()->toDateString())->first();
        $this->assertSame(1, (int) $hoy->opens);
        $this->assertSame(2, (int) $hoy->engaged);
        $this->assertSame(1, (int) $hoy->readers, 'Dos lecturas del mismo visitante en el día son un lector.');
    }

    /** La hemeroteca es la misma lista más atrás, y el sitemap solo lleva lo publicado e indexable. */
    public function test_la_lista_publica_pagina_y_el_sitemap_lleva_lo_indexable(): void
    {
        $seccion = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $firma = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        foreach ([['a', '2026-07-03', []], ['b', '2026-07-02', []], ['c', '2026-07-01', ['noindex' => true]], ['d', '2026-06-30', ['status' => 'draft']]] as [$slug, $dia, $extra]) {
            SnkKilkanaPostModel::create($extra + [
                'title' => $slug, 'slug' => $slug, 'url_date' => $dia, 'category_id' => $seccion->id, 'author_id' => $firma->id,
                'status' => 'published', 'publish_at' => "{$dia} 08:00:00", 'excerpt' => 'E.', 'body_blocks' => [],
            ]);
        }
        $servicio = app(SnkKilkanaPublicService::class);

        $this->assertSame(['a', 'b'], $servicio->posts(['per_page' => 2])->pluck('slug')->all());
        $this->assertSame(['c'], $servicio->posts(['per_page' => 2, 'page' => 2])->pluck('slug')->all());
        $this->assertSame([], $servicio->posts(['per_page' => 2, 'page' => 3])->pluck('slug')->all());

        $this->assertSame(['/2026/07/03/a', '/2026/07/02/b'], array_column($servicio->sitemap(), 'path'));
    }

    /** Una etiqueta en la calle: su rótulo y sus piezas, y nada si no existe. */
    public function test_la_lista_publica_filtra_por_etiqueta_y_la_etiqueta_se_rotula(): void
    {
        $seccion = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $firma = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        $sequia = SnkKilkanaTagModel::create(['name' => 'Sequía', 'slug' => 'sequia']);
        foreach (['a', 'b'] as $slug) {
            $pieza = SnkKilkanaPostModel::create([
                'title' => $slug, 'slug' => $slug, 'url_date' => '2026-07-01', 'category_id' => $seccion->id, 'author_id' => $firma->id,
                'status' => 'published', 'publish_at' => '2026-07-01 08:00:00', 'excerpt' => 'E.', 'body_blocks' => [],
            ]);
            if ($slug === 'a') {
                $pieza->tags()->attach($sequia->id);
            }
        }
        $servicio = app(SnkKilkanaPublicService::class);

        $this->assertSame(['a'], $servicio->posts(['tag' => 'sequia'])->pluck('slug')->all());
        $this->assertSame(['name' => 'Sequía', 'slug' => 'sequia'], $servicio->tag('sequia'));
        $this->assertNull($servicio->tag('nada'));
    }

    /**
     * El listado de la calle no paga lo que no enseña.
     *
     * `makeHidden('body_blocks')` escondía el cuerpo **al serializar**, después de haberlo
     * traído: una portada de cien piezas movía 239 KB de cuerpos de MariaDB a PHP para
     * tirarlos al final. Y `views_day`/`views_week` eran dos subconsultas correlacionadas por
     * fila que no lee nadie, ni la calle ni el panel.
     *
     * Se comprueba sobre el SQL **y** sobre la fila, porque son dos fallos distintos: el
     * primero no se ve en la respuesta —el JSON salía igual con y sin el cuerpo— y el segundo
     * sí.
     */
    public function test_el_listado_publico_no_trae_el_cuerpo_ni_suma_lo_que_nadie_pinta(): void
    {
        $this->piezas(['a']);
        // Lo que pide el frontend: sin `?fields=` la puerta sirve su techo, y las cuatro
        // relaciones se incluyen por su nombre.
        request()->query->replace(['include' => 'category,author,tags,coverMedia']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $pieza = app(SnkKilkanaPublicService::class)->posts(['per_page' => 5])->first();
        $sql = DB::getQueryLog()[0]['query'];
        DB::disableQueryLog();
        $fila = json_decode(json_encode(new SnkKilkanaPostResource($pieza)), true);

        $this->assertStringNotContainsString('body_blocks', $sql, 'El listado se trae el cuerpo entero de cada pieza.');
        $this->assertStringNotContainsString('views_day', $sql);
        $this->assertStringNotContainsString('views_week', $sql);
        $this->assertStringContainsString('views_total', $sql);

        // Lo que la calle lee de una pieza. La lista está escrita a mano: si alguien recorta el
        // techo de más, esto se pone rojo antes de que el periódico pierda una portada.
        foreach (['id', 'title', 'excerpt', 'slug', 'status', 'content_tier', 'publish_at', 'unpublish_at',
            'updated_at', 'url_path', 'views_total', 'comments_count', 'cover', 'category', 'author', 'tags',
            'noindex', 'seo_title', 'seo_description', 'og_title', 'og_description', 'og_image_id'] as $campo) {
            $this->assertArrayHasKey($campo, $fila, "«{$campo}» lo lee la calle y ha dejado de viajar.");
        }
        foreach (['body_blocks', 'created_by', 'updated_by', 'deleted_by', 'deleted_at',
            'canonical_url', 'featured', 'views_day', 'views_week'] as $campo) {
            $this->assertArrayNotHasKey($campo, $fila, "«{$campo}» es de dentro y no pinta nada en la calle.");
        }
    }

    /**
     * El sitemap devuelve dos campos por pieza, y eso es lo que tiene que costar.
     *
     * `get(['url_path','updated_at'])` no proyectaba nada: el `$withCount` que declara el
     * modelo fija `kilkana_posts.*` en cuanto se construye la consulta, así que esto traía el
     * archivo publicado entero —cuerpos incluidos— más las cuatro relaciones que el modelo
     * carga solo. `url_path` ni siquiera es una columna, es un accesor: lo único que evitaba
     * un «Unknown column» era que el argumento se ignorase.
     */
    public function test_el_sitemap_no_carga_relaciones_ni_columnas_que_no_devuelve(): void
    {
        $this->piezas(['a', 'b']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $mapa = app(SnkKilkanaPublicService::class)->sitemap();
        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $consultas, 'El sitemap carga relaciones que no devuelve.');
        $this->assertStringNotContainsString('body_blocks', $consultas[0]['query']);
        $this->assertSame(['path', 'updated_at'], array_keys($mapa[0]));
    }

    /**
     * Dos piezas del mismo minuto salen siempre en el mismo orden.
     *
     * Sin desempate, `order by publish_at desc` no es un orden total y la base puede devolver
     * los empates como quiera —cambió al recortar las columnas, porque cambió el plan—. Esta
     * lista se pagina con `forPage`, así que un orden inestable hace que la página 2 repita
     * una pieza y se salte otra.
     */
    public function test_dos_piezas_del_mismo_minuto_salen_siempre_en_el_mismo_orden(): void
    {
        $this->piezas(['a', 'b', 'c'], '2026-07-01 08:00:00');
        $servicio = app(SnkKilkanaPublicService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $primera = $servicio->posts(['per_page' => 2])->pluck('slug')->all();
        $sql = DB::getQueryLog()[0]['query'];
        DB::disableQueryLog();
        $segunda = $servicio->posts(['per_page' => 2, 'page' => 2])->pluck('slug')->all();

        // Sobre el SQL y no solo sobre el resultado: con tres filas en sqlite los empates salen
        // estables por casualidad, así que comprobar el orden devuelto no fija nada. Lo que hay
        // que fijar es que el desempate esté escrito.
        $this->assertMatchesRegularExpression(
            '/order by .*publish_at.* desc, .*id.* desc/',
            $sql,
            'El listado no desempata, así que su orden lo decide el plan de la consulta.',
        );

        $this->assertCount(2, $primera);
        $this->assertSame([], array_intersect($primera, $segunda), 'La página 2 repite una pieza de la página 1.');
        $this->assertSame($primera, $servicio->posts(['per_page' => 2])->pluck('slug')->all(), 'La misma consulta da otro orden.');
    }

    /**
     * Piezas publicadas con cuerpo, para medir lo que cuesta traerlo.
     *
     * @param  list<string>  $slugs
     */
    /**
     * Quien llama elige los campos, dentro del techo del servicio.
     *
     * Y elige de verdad: `url_path` no es una columna sino un accesor sobre `url_date` y
     * `slug`, así que pedirlo tiene que traer las dos o devolvería `null` sin decir por qué
     * —la trampa que ya pagó el sitemap—. Lo que no está en el techo se ignora, como el resto
     * de la query: nunca un 422, y nunca una columna que la calle no puede ver.
     */
    public function test_la_calle_elige_los_campos_y_no_puede_pedir_los_que_no_son_suyos(): void
    {
        $this->piezas(['una', 'otra']);
        $fila = function (string $campos) {
            request()->query->replace($campos === '' ? [] : ['fields' => $campos]);

            return json_decode(json_encode(new SnkKilkanaPostResource(
                app(SnkKilkanaPublicService::class)->posts([])->first(),
            )), true);
        };

        // Sin pedir nada, el techo: la lista blanca de la puerta, y **no la fila entera**.
        // El paquete, cuando nadie recorta, selecciona todo; en una puerta anónima eso sería
        // servir `body_blocks` con su muro de pago dentro a quien pida `/public/posts` a secas.
        $entera = $fila('');
        $this->assertArrayHasKey('seo_title', $entera);
        $this->assertArrayNotHasKey('body_blocks', $entera, 'El cuerpo no sale de aquí ni por defecto.');

        // Y elige de verdad: `url_path` no es una columna sino un derivado de `url_date` y
        // `slug`, así que se pide su columna y el Resource lo compone.
        $minima = $fila('id,title,url_date,slug');

        $this->assertSame(
            ['id', 'title', 'slug', 'views_total', 'url_path', 'comments_count'],
            array_keys($minima),
            'Ni una relación: el modelo dejó de cargarlas solo y aquí no se ha incluido ninguna.',
        );
        $this->assertNotNull($minima['url_path'], 'El derivado sale con la columna de la que depende.');

        // Cuatro columnas pedidas, `url_path` como derivado de `url_date`, y los dos agregados
        // que esta puerta cuelga siempre. Nada más.
        foreach (['excerpt', 'seo_title', 'og_title', 'publish_at', 'noindex'] as $noPedida) {
            $this->assertArrayNotHasKey($noPedida, $minima, "`{$noPedida}` no se pidió: no viaja.");
        }

        // El techo: lo que no es suyo lanza, que es lo único que `?fields=` no perdona —no
        // tiene bandera para ignorarlo— y aquí es lo correcto: pedir `created_by` por su
        // nombre no puede devolver la lista como si tal cosa.
        request()->query->replace(['fields' => 'id,created_by']);
        $this->expectException(InvalidFieldQuery::class);
        app(SnkKilkanaPublicService::class)->posts([]);
    }

    private function piezas(array $slugs, ?string $cuando = null): void
    {
        $seccion = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $firma = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);

        foreach ($slugs as $i => $slug) {
            SnkKilkanaPostModel::create([
                'title' => $slug, 'slug' => $slug, 'url_date' => '2026-07-01',
                'category_id' => $seccion->id, 'author_id' => $firma->id, 'status' => 'published',
                'publish_at' => $cuando ?? '2026-07-0'.($i + 1).' 08:00:00', 'excerpt' => 'E.',
                'body_blocks' => [['id' => 'p1', 'type' => 'paragraph', 'config' => ['text' => str_repeat('x', 500)]]],
            ]);
        }
    }
}
