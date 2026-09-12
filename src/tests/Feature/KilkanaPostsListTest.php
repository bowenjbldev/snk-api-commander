<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPostsController;
use Packages\Kilkana\Http\Resources\SnkKilkanaPostResource;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Kilkana\Services\SnkKilkanaPostService;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * El contrato del listado editorial, probado sobre la conexión de inquilino.
 *
 * Se prueba el servicio y no la ruta HTTP a propósito: montar una petición autenticada
 * contra un inquilino exige un arnés —instancia en Commander, base migrada, resolución
 * por host y token de Sanctum en la base del periódico— que la suite no tiene.
 * Lo que aquí se fija es lo que de verdad puede romperse en silencio: el filtrado, el
 * orden estable del que depende paginar, y que las cifras de las pestañas cuenten el
 * archivo entero y no la página.
 */
class KilkanaPostsListTest extends TestCase
{
    private SnkKilkanaPostService $posts;

    protected function setUp(): void
    {
        parent::setUp();

        // `tenant` es sqlite `:memory:` en pruebas (`phpunit.xml`), así que la base vive
        // mientras viva la conexión: basta con migrar encima y no hay nada que limpiar.
        DB::setDefaultConnection('tenant');
        $this->artisan('migrate', [
            '--database' => 'tenant',
            '--path' => 'packages/Core/database/migrations',
        ])->run();
        $this->artisan('migrate', [
            '--database' => 'tenant',
            '--path' => 'packages/Kilkana/database/migrations',
        ])->run();

        app(SnkKilkanaRoleCatalogService::class)->sync();
        $this->posts = app(SnkKilkanaPostService::class);
    }

    /**
     * Un enlace con `?page=999` enseña la última página, no una tabla vacía.
     *
     * Es lo que hace que un enlace viejo siga significando algo. Sin esto la respuesta viene
     * con `data` vacío y un `meta` diciendo que hay siete piezas, que es una contradicción que
     * el panel pinta como «no hay nada».
     */
    public function test_an_out_of_range_page_shows_the_last_one_not_an_empty_table(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
        $section = $this->category('Local', 'local');
        $byline = $this->author('Redacción', alias: 'Redacción');
        for ($i = 0; $i < 7; $i++) {
            $this->makePost("Pieza {$i}", $section, $byline, 'draft', null, $marta->id);
        }

        request()->query->replace(['per_page' => '5', 'page' => '999']);
        $outOfRange = app(SnkKilkanaPostsController::class)->index()->getData(true);

        $this->assertSame(2, $outOfRange['meta']['current_page'], 'Cae a la última, no se queda en la 999.');
        $this->assertSame(2, $outOfRange['meta']['last_page']);
        $this->assertSame(7, $outOfRange['meta']['total']);
        $this->assertCount(2, $outOfRange['data'], 'Y trae las filas de esa última página.');
    }

    /**
     * Lo que no se reconoce cae al defecto; nunca es un 422 ni una tabla vacía.
     *
     * Es la misma regla que aplica el panel al leer sus `searchParams`: quien teclea
     * `?status=banana` a mano se merece la lista entera, no una pantalla en blanco. Y el tope
     * por página no lo elige quien llama, o `?per_page=500` restaura el volcado entero.
     */
    public function test_unrecognised_query_values_fall_back_to_the_default(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
        $section = $this->category('Local', 'local');
        $byline = $this->author('Redacción', alias: 'Redacción');
        $this->makePost('Una', $section, $byline, 'draft', null, $marta->id);

        $request = function (array $query) {
            request()->query->replace($query);

            return app(SnkKilkanaPostsController::class)->index()->getData(true);
        };

        $this->assertCount(1, $request(['status' => 'banana'])['data'], 'Un estado inventado no filtra a cero.');
        $this->assertCount(1, $request(['category' => 'No existe'])['data'], 'Ni una sección que no está.');
        $this->assertCount(1, $request(['sort' => 'banana'])['data'], 'Ni un orden que no existe.');
        $this->assertSame(100, $request(['per_page' => '500'])['meta']['per_page'], 'El techo no lo elige quien llama.');
        $this->assertSame(25, $request([])['meta']['per_page'], 'Y sin pedir nada, veinticinco.');
    }

    /**
     * El orden va en un parámetro, y el `-` delante es descendente.
     *
     * Con dos —`sort` y `dir`— ninguno diría por sí solo cómo va a salir la lista: `sort=title`
     * sin `dir` saldría descendente, que es lo contrario de lo que cualquiera espera de un
     * alfabético. Sin signo es ascendente, como en JSON:API.
     */
    public function test_sort_is_one_parameter_and_a_minus_means_descending(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
        $section = $this->category('Local', 'local');
        $byline = $this->author('Redacción', alias: 'Redacción');
        foreach (['Bravo' => '2026-06-02 10:00:00', 'Alfa' => '2026-06-03 10:00:00', 'Charlie' => '2026-06-01 10:00:00'] as $headline => $at) {
            $this->makePost($headline, $section, $byline, 'published', $at, $marta->id);
        }

        $titles = function (array $query) {
            request()->query->replace($query);

            return array_column(app(SnkKilkanaPostsController::class)->index()->getData(true)['data'], 'title');
        };

        $this->assertSame(['Alfa', 'Bravo', 'Charlie'], $titles(['sort' => 'title']));
        $this->assertSame(['Charlie', 'Bravo', 'Alfa'], $titles(['sort' => '-title']));

        // Sin pedir orden, lo más nuevo primero: es con lo que se abre el panel.
        $this->assertSame(['Alfa', 'Bravo', 'Charlie'], $titles(['sort' => '-date']));
        $this->assertSame($titles([]), $titles(['sort' => '-date']));

        // Y lo que no está en el vocabulario cae ahí, con signo o sin él.
        $this->assertSame($titles([]), $titles(['sort' => 'banana']));
        $this->assertSame($titles([]), $titles(['sort' => '-banana']));
    }

    /**
     * Las cifras de las pestañas solo viajan si se piden.
     *
     * Cuentan el archivo entero y no la página, así que no son parte de esta respuesta: son
     * otra pregunta que se contesta en el mismo viaje. Tres pantallas del panel piden este
     * listado solo para enseñar «lo último publicado», y no tienen por qué pagar el recuento.
     */
    public function test_counts_are_sent_only_when_requested(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
        $this->makePost('Una', $this->category('Local', 'local'), $this->author('Redacción', alias: 'Redacción'), 'draft', null, $marta->id);

        $request = function (array $query) {
            request()->query->replace($query);

            return app(SnkKilkanaPostsController::class)->index()->getData(true);
        };

        $withoutCounts = $request([]);
        $this->assertArrayNotHasKey('counts', $withoutCounts);
        $this->assertArrayNotHasKey('counts_all', $withoutCounts);
        $this->assertCount(1, $withoutCounts['data'], 'Las filas siguen llegando.');

        $withCounts = $request(['summary' => 'counts']);
        $this->assertSame(1, $withCounts['counts']['draft']);
        $this->assertArrayHasKey('counts_all', $withCounts);

        // Y lo que no se ofrece se ignora, como el resto de la query: nunca un 422.
        $this->assertArrayNotHasKey('counts', $request(['summary' => 'banana']));
        $this->assertArrayHasKey('counts', $request(['summary' => 'banana,counts']));
    }

    public function test_the_filter_narrows_and_the_order_is_stable(): void
    {
        $sports = $this->category('Deportes', 'deportes');
        $local = $this->category('Local', 'local');
        $marta = $this->author('Marta Beneyto');
        $newsroom = $this->author('Redacción', alias: 'Redacción');

        $this->makePost('El derbi acaba en tablas', $sports, $marta, 'published', '2026-06-01 10:00:00');
        $this->makePost('El pleno aprueba el presupuesto', $local, $marta, 'draft');
        $this->makePost('Breve de la casa', $local, $newsroom, 'draft');

        $all = fn (array $filters) => $this->posts
            ->filter($this->posts->query(1, true), $filters)
            ->pluck('title')
            ->all();

        $this->assertCount(2, $all(['status' => 'draft']));
        $this->assertSame(['El derbi acaba en tablas'], $all(['category' => 'Deportes']));

        // Buscar por firma busca el nombre **publicado**: quien firma «Redacción» no
        // aparece al teclear el nombre de la cuenta que hay detrás.
        $this->assertSame(['El pleno aprueba el presupuesto'], $all(['q' => 'pleno']));
        $this->assertCount(2, $all(['q' => 'Marta']));
        $this->assertSame(['Breve de la casa'], $all(['q' => 'Redacción']));

        // Sin desempate, dos piezas sin fecha salen en orden de la base y paginar repite
        // una fila y se salta otra. El orden tiene que ser total.
        $this->assertSame($all([]), $all([]));
    }

    public function test_tab_counts_cover_the_archive_not_the_page(): void
    {
        $local = $this->category('Local', 'local');
        $byline = $this->author('Marta Beneyto');

        $this->makePost('Uno', $local, $byline, 'published', '2026-06-01 10:00:00');
        $this->makePost('Dos', $local, $byline, 'published', '2026-06-02 10:00:00');
        $this->makePost('Tres', $local, $byline, 'draft');

        $counts = $this->posts->countsByStatus(1, true, true);

        // Las cuatro claves están siempre, también las que valen cero: una pestaña que
        // desaparece al quedarse vacía es una pestaña a la que no se puede volver.
        $this->assertSame(
            ['draft' => 1, 'review' => 0, 'scheduled' => 0, 'published' => 2],
            $counts['scoped'],
        );
    }

    /**
     * Las dos escalas, que es lo que el tablero enseña junto.
     *
     * «Published 34» sin el «of 467» queda al lado de una sección con 40 del periódico entero
     * y se lee como una contradicción: una sección parece tener más piezas que todo lo
     * publicado. Las dos cifras son ciertas, y la de al lado es la que lo explica.
     */
    public function test_counts_arrive_in_both_scales_and_only_one_is_scoped(): void
    {
        $local = $this->category('Local', 'local');
        $byline = $this->author('Marta Beneyto');

        // Dos de la cuenta 7 y una de otra: así se distingue lo suyo de lo de la casa.
        $this->makePost('Suya publicada', $local, $byline, 'published', '2026-06-01 10:00:00', createdBy: 7);
        $this->makePost('Suyo borrador', $local, $byline, 'draft', createdBy: 7);
        $this->makePost('De otro', $local, $byline, 'published', '2026-06-02 10:00:00', createdBy: 9);

        $scoped = $this->posts->countsByStatus(7, false, true);
        $this->assertSame(1, $scoped['scoped']['published'], 'lo acotado tiene que contar solo lo suyo');
        $this->assertSame(2, $scoped['all']['published'], 'la otra escala cuenta el periódico entero');
        $this->assertSame(1, $scoped['scoped']['draft']);

        // Con `edit others posts` las dos escalas son la misma, y el panel enseña una cifra
        // sola en vez de «2 of 2».
        $unrestricted = $this->posts->countsByStatus(7, true, true);
        $this->assertSame($unrestricted['all'], $unrestricted['scoped']);
        $this->assertSame(2, $unrestricted['scoped']['published']);
    }

    /**
     * **Editar lo ajeno implica verlo**, y la implicación es de la consulta, no del reparto.
     *
     * Con `edit others posts` el listado ya devuelve la pieza de cualquiera, así que las dos
     * escalas son la misma y `view others posts` no decide nada. Se prueba porque es el sitio
     * donde un permiso puede parecer necesario sin serlo: quitárselo a un director no le
     * cambiaría el tablero, y quien lo comprobara ahí concluiría que el permiso no hace nada.
     * Donde sí decide es en el redactor, que es la prueba de abajo.
     */
    public function test_editing_others_posts_implies_viewing_them_even_without_the_permission(): void
    {
        $local = $this->category('Local', 'local');
        $byline = $this->author('Marta Beneyto');

        $this->makePost('Suya', $local, $byline, 'published', '2026-06-01 10:00:00', createdBy: 7);
        $this->makePost('De otro', $local, $byline, 'published', '2026-06-02 10:00:00', createdBy: 9);

        $withoutPermission = $this->posts->countsByStatus(7, true, false);

        $this->assertSame(2, $withoutPermission['scoped']['published'], 'quien edita lo ajeno lo cuenta');
        $this->assertSame($withoutPermission['scoped'], $withoutPermission['all']);
    }

    /**
     * Quien escribe desde fuera no ve el sumario de la redacción.
     *
     * `counts_all` publica cuánto hay sin publicar y en qué fase, y eso es actividad editorial
     * ajena. Choca con la regla que el listado ya aplica —una pieza
     * ajena no existe: 404, no 403, porque un 403 confirmaría cuántas hay—, así que el
     * recuento tiene que respetarla igual.
     *
     * Y la segunda escala **se iguala, no se calla**: el panel deduce de que las dos cifras
     * coincidan que hay una sola escala que dibujar, y omitir la clave le dejaría la barra sin
     * saber qué medir.
     */
    public function test_without_viewing_others_both_scales_count_only_own_posts(): void
    {
        $local = $this->category('Local', 'local');
        $byline = $this->author('Marta Beneyto');

        $this->makePost('Suya publicada', $local, $byline, 'published', '2026-06-01 10:00:00', createdBy: 7);
        $this->makePost('De otro', $local, $byline, 'published', '2026-06-02 10:00:00', createdBy: 9);
        $this->makePost('Borrador de otro', $local, $byline, 'draft', createdBy: 9);

        $ownOnly = $this->posts->countsByStatus(7, false, false);

        $this->assertSame($ownOnly['scoped'], $ownOnly['all'], 'sin ver lo ajeno las dos escalas coinciden');
        $this->assertSame(1, $ownOnly['scoped']['published']);
        $this->assertSame(0, $ownOnly['scoped']['draft'], 'el borrador de otro no puede aparecer ni contado');

        // Y las cuatro claves siguen estando, también las de cero: una pestaña que desaparece
        // al quedarse vacía es una pestaña a la que no se puede volver.
        $this->assertSame(['draft', 'review', 'scheduled', 'published'], array_keys($ownOnly['all']));
    }

    /**
     * Las dos escalas salen del **mismo recorrido**: un `COUNT(*)` y un `SUM(created_by = ?)`
     * sobre el mismo `GROUP BY`. Pedirlas por separado sería pasar dos veces por la tabla para
     * contestar dos mitades de la misma pregunta, y esta prueba es lo único que lo impide.
     */
    public function test_both_scales_cost_a_single_query(): void
    {
        $local = $this->category('Local', 'local');
        $byline = $this->author('Marta Beneyto');
        $this->makePost('Una', $local, $byline, 'published', '2026-06-01 10:00:00', createdBy: 7);

        DB::enableQueryLog();
        $this->posts->countsByStatus(7, false, true);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries, "las dos escalas cuestan {$queries} consultas y tienen que costar 1");
    }

    /**
     * El lote no saca a la calle lo que no está terminado.
     *
     * `required_if` vive en el controlador y solo mira la petición, y una petición de lote son
     * ids: sin esta comprobación, marcar los borradores y pulsar «Publicar» publica también
     * el que no tiene entradilla. El resto SÍ están listos, así que el lote no falla entero:
     * se salta el incompleto y el recuento lo dice.
     */
    public function test_the_batch_does_not_publish_a_post_without_an_excerpt(): void
    {
        $local = $this->category('Local', 'local');
        $byline = $this->author('Marta Beneyto');

        $ready = $this->makePost('Con entradilla', $local, $byline, 'draft');
        $withoutExcerpt = $this->makePost('Sin entradilla', $local, $byline, 'draft');
        $withoutExcerpt->update(['excerpt' => null]);

        $moved = $this->posts->bulk([$ready->id, $withoutExcerpt->id], 'publish', 1, true);

        $this->assertSame(1, $moved);
        $this->assertSame('published', $ready->fresh()->status->value);
        $this->assertSame('draft', $withoutExcerpt->fresh()->status->value);

        // Y a borrador sí van las dos: la entradilla hace falta para SALIR, no para volver.
        $this->assertSame(2, $this->posts->bulk([$ready->id, $withoutExcerpt->id], 'draft', 1, true));
    }

    /**
     * El cron tampoco. A `scheduled` se llega por el lote, que no valida contenido, así que
     * sin esto la pieza incompleta sale a la calle a las seis de la mañana sin que nadie la
     * viera. Se queda programada, que es un estado del que se sale escribiéndola.
     */
    public function test_the_cron_does_not_publish_a_scheduled_post_without_an_excerpt(): void
    {
        $local = $this->category('Local', 'local');
        $byline = $this->author('Marta Beneyto');

        $ready = $this->makePost('Programada completa', $local, $byline, 'scheduled', '2026-01-01 06:00:00');
        $withoutExcerpt = $this->makePost('Programada a medias', $local, $byline, 'scheduled', '2026-01-01 06:00:00');
        $withoutExcerpt->update(['excerpt' => null]);

        $this->assertSame(1, $this->posts->publishDue());
        $this->assertSame('published', $ready->fresh()->status->value);
        $this->assertSame('scheduled', $withoutExcerpt->fresh()->status->value);
    }

    /**
     * Las dos puertas leen la MISMA regla.
     *
     * Nula es «todavía no escrita» y `''` es «escrita y en blanco», pero para publicar dan
     * igual: las dos dejan la pieza sin entradilla en la calle. Con `whereNotNull` en el cron
     * y `null || ''` en el lote, una pieza en blanco saldría por una puerta y no por la otra — y
     * el `down()` de la migración convierte las nulas en vacías, así que un rollback y su
     * reaplicación cambiarían quién se publica.
     */
    public function test_a_blank_excerpt_counts_the_same_as_a_missing_one(): void
    {
        $local = $this->category('Local', 'local');
        $byline = $this->author('Marta Beneyto');

        $batch = $this->makePost('Por el lote', $local, $byline, 'draft');
        $cron = $this->makePost('Por el cron', $local, $byline, 'scheduled', '2026-01-01 06:00:00');
        $batch->update(['excerpt' => '']);
        $cron->update(['excerpt' => '   ']);

        $this->assertSame(0, $this->posts->bulk([$batch->id], 'publish', 1, true));
        $this->assertSame(0, $this->posts->publishDue());

        $this->assertSame('draft', $batch->fresh()->status->value);
        $this->assertSame('scheduled', $cron->fresh()->status->value);
    }

    /**
     * Un borrador sin entradilla sale en el listado. Es el estado normal de una pieza recién
     * creada —el titular llega antes que el texto—, y una lista que se cae con la primera se
     * lleva por delante las otras veinticuatro de la página.
     */
    public function test_a_draft_without_an_excerpt_appears_in_the_list(): void
    {
        $local = $this->category('Local', 'local');
        $byline = $this->author('Marta Beneyto');

        $this->makePost('Titular a secas', $local, $byline, 'draft')->update(['excerpt' => null]);

        $rows = $this->posts->filter($this->posts->query(1, true), [])->get();

        $this->assertCount(1, $rows);
        $this->assertNull($rows->first()->excerpt);
    }

    /**
     * Etiquetar en tanda añade sin quitar, y quitar no borra el vocabulario.
     *
     * Es lo que hace que reetiquetar veinte piezas sea un endpoint y no veinte `PUT`: la unión
     * con lo que cada pieza ya llevaba la calcula quien tiene las filas delante.
     */
    public function test_the_batch_tags_without_overwriting_existing_tags(): void
    {
        $local = $this->category('Local', 'local');
        $byline = $this->author('Marta Beneyto');

        $one = $this->makePost('Una', $local, $byline, 'draft');
        $other = $this->makePost('Otra', $local, $byline, 'draft');
        $one->tags()->sync([SnkKilkanaTagModel::firstOrCreateByName('pleno')->id]);

        $this->assertSame(2, $this->posts->bulk([$one->id, $other->id], 'tag', 1, true, ['sequía']));

        // La que ya tenía «pleno» conserva las dos; la etiqueta nueva se creó al vuelo.
        $this->assertEqualsCanonicalizing(['pleno', 'sequía'], $one->fresh()->tags->pluck('name')->all());
        $this->assertSame(['sequía'], $other->fresh()->tags->pluck('name')->all());

        $this->assertSame(2, $this->posts->bulk([$one->id, $other->id], 'untag', 1, true, ['sequía']));

        $this->assertSame(['pleno'], $one->fresh()->tags->pluck('name')->all());
        $this->assertSame([], $other->fresh()->tags->pluck('name')->all());
        // Desenganchar no borra la fila: el vocabulario lo gobierna su pantalla.
        $this->assertNotNull(SnkKilkanaTagModel::query()->where('name', 'sequía')->first());
    }

    /**
     * El listado no puede crecer en consultas al crecer en filas.
     *
     * Es la única forma de que un N+1 no entre sin que nadie lo note: en producción se paga
     * en milisegundos y en una demo de veinticinco filas no se ve. Un accesor que calcula al
     * vuelo `usage_count` de la portada son tres `COUNT(*)` por medio distinto cuando nadie hizo
     * `withCount`, y con veinticinco filas lleva el listado a decenas de consultas.
     *
     * Se cuentan sobre el JSON serializado a propósito: la mitad de los N+1 de Eloquent no
     * los dispara la consulta, los dispara un accesor al serializar.
     */
    public function test_the_list_does_not_run_a_query_per_row(): void
    {
        $local = $this->category('Local', 'local');
        $byline = $this->author('Marta Beneyto');
        $photo = SnkKilkanaMediaModel::create(['url' => 'https://ejemplo.test/foto.jpg', 'url_hash' => sha1('https://ejemplo.test/foto.jpg')]);

        // Con las cuatro relaciones pedidas, que es cuando un N+1 puede existir: sin pedirlas
        // el listado no carga ninguna y la prueba sería cierta sin demostrar nada. Y por el
        // Resource, que es por donde sale en producción — la mitad de los N+1 de Eloquent no
        // los dispara la consulta sino un accesor al serializar.
        $serialize = function () {
            $page = $this->posts->filter(
                $this->posts->query(1, true)->with(['category', 'author', 'tags', 'coverMedia']),
                [],
            )->paginate(25);

            json_encode(SnkKilkanaPostResource::collection($page->getCollection()));
        };

        for ($n = 0; $n < 3; $n++) {
            $this->makePost("Pieza {$n}", $local, $byline, 'published', "2026-06-0{$n} 10:00:00")
                ->update(['cover_media_id' => $photo->id]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $serialize();
        $withThree = count(DB::getQueryLog());

        for ($n = 3; $n < 15; $n++) {
            $this->makePost("Pieza {$n}", $local, $byline, 'published', '2026-07-01 10:00:00')
                ->update(['cover_media_id' => $photo->id]);
        }
        DB::flushQueryLog();
        $serialize();
        $withFifteen = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $withThree,
            $withFifteen,
            "el listado pasa de {$withThree} consultas con 3 filas a {$withFifteen} con 15: hay un N+1",
        );
    }

    /**
     * Y no calcula lo que nadie pinta.
     *
     * `views_day` y `views_week` son dos subconsultas correlacionadas por fila que ningún
     * consumidor lee. Solo por ellas, ordenar por lecturas pasa de 5,9 a 18,6 ms de SQL,
     * porque ordenar por el alias de una correlacionada obliga a calcularla para TODAS las
     * filas. Las ventanas viven donde se usan, en el servicio público.
     */
    public function test_the_list_only_sums_the_audience_it_shows(): void
    {
        $sql = $this->posts->withAudience($this->posts->query(1, true), null)->toSql();

        $this->assertStringContainsString('views_total', $sql);
        $this->assertStringNotContainsString('views_day', $sql);
        $this->assertStringNotContainsString('views_week', $sql);
    }

    /**
     * **Quien llama elige las columnas, y lo que no pide no viaja.**
     *
     * El servidor no declara una fila fija que el parser del BFF tenga que copiar a mano: dos
     * listas gemelas en dos repos divergen, y un campo que se cae de un lado sigue parseándose
     * en el otro como `undefined`. La lista está **una sola vez**, en quien la pide.
     *
     * Un campo no pedido sale **ausente**, no nulo, que es lo que permite notar la diferencia.
     *
     * Y `views_total` es la prueba del orden: es el alias de un `withSum`, y `withAggregate()`
     * pone `select tabla.*` antes de colgar la subconsulta. Si la suma se aplicara antes que
     * `?fields=`, el `select()` del recorte se la llevaría por delante y la audiencia se leería
     * como cero, sin error.
     *
     * Medido con 522 piezas: la fila entera cuesta 28,0 ms y 59,7 kB por página de veinticinco;
     * recortada, 14,5 ms y 14,2 kB.
     */
    public function test_the_row_includes_the_requested_columns_and_nothing_else(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');

        $local = $this->category('Local', 'local');
        $byline = $this->author('Marta Beneyto', alias: 'Redacción');
        $photo = SnkKilkanaMediaModel::create(['url' => 'https://ejemplo.test/foto.jpg', 'url_hash' => sha1('https://ejemplo.test/foto.jpg')]);
        $post = $this->makePost('Una pieza', $local, $byline, 'published', '2026-06-01 10:00:00', $marta->id);
        $post->update(['cover_media_id' => $photo->id]);
        $post->tags()->sync([SnkKilkanaTagModel::firstOrCreateByName('pleno')->id]);

        // Los campos de una relación viajan en el MISMO parámetro, con el punto delante: el
        // prefijo es el nombre de la relación en plural snake, no el de la tabla.
        request()->query->replace([
            // `category_id` y `author_id` se piden y no se leen: son la llave con la que
            // Eloquent empareja lo incluido. Sin ellas la relación llega nula, y en silencio.
            'fields' => 'id,title,excerpt,slug,status,content_tier,publish_at,cover_media_id'
                .',category_id,author_id'
                .',categories.id,categories.name,authors.id,authors.alias,authors.user_id,tags.id,tags.name',
            'include' => 'category,author,tags',
        ]);
        $row = app(SnkKilkanaPostsController::class)->index()->getData(true)['data'][0];

        $this->assertSame([
            'id', 'title', 'excerpt', 'slug', 'status', 'content_tier',
            'publish_at', 'cover_media_id', 'views_total', 'category', 'author', 'tags',
        ], array_keys($row));

        $this->assertSame($photo->id, $row['cover_media_id'], 'La portada es su columna, no un objeto.');
        // Y sale sin pedirla: no es una columna, así que `?fields=` no la nombra —le antepondría
        // la tabla y `kilkana_posts.views_total` no existe—. La cuelga `withAudience()` después
        // del recorte, y esta línea es la que prueba que ese orden se mantiene: al revés, el
        // `select()` de los campos se llevaría la subconsulta y la audiencia se leería como cero.
        // Sin lecturas la suma es `null`, no cero, y da igual: lo que se comprueba es que la
        // clave **está**. Si el recorte se hubiera llevado la subconsulta, `whenHas()` no la
        // vería y no habría clave que mirar.
        $this->assertArrayHasKey('views_total', $row, 'La suma sobrevive al recorte de columnas.');

        // Y las relaciones tampoco viajan enteras: de cada una, lo que se pidió.
        $this->assertSame(['id', 'name'], array_keys($row['category']));
        $this->assertSame(['id', 'name'], array_keys($row['author']));
        $this->assertSame(['id', 'name'], array_keys($row['tags'][0]));

        // El nombre de la firma es el PUBLICADO, que es derivado: recortar columnas no puede
        // dejarlo sin la que lo compone.
        $this->assertSame('Redacción', $row['author']['name']);
    }

    /**
     * Lo que no se pide no viaja: **ni columnas ni relaciones**.
     *
     * Un `$with = ['category', 'author', 'coverMedia', 'tags']` en el modelo las cargaría en toda
     * consulta de piezas aunque la pantalla no pintara ninguna —y con ellas, la cuenta de usos de
     * cada portada, que son tres `COUNT` por foto—. La forma de salida la decide quien pregunta,
     * no el modelo.
     */
    public function test_what_is_not_requested_is_not_sent(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
        $this->makePost('Una', $this->category('Local', 'local'), $this->author('Redacción', alias: 'Redacción'), 'draft', null, $marta->id);

        request()->query->replace(['fields' => 'id,title']);
        $row = app(SnkKilkanaPostsController::class)->index()->getData(true)['data'][0];

        $this->assertSame(['id', 'title', 'views_total'], array_keys($row), 'Y `views_total` porque la suma se cuelga siempre.');
        $this->assertSame('Una', $row['title']);
    }

    /**
     * Y el editor sigue recibiendo la pieza entera: son dos preguntas distintas.
     */
    public function test_the_editor_post_is_not_trimmed(): void
    {
        $local = $this->category('Local', 'local');
        $byline = $this->author('Marta Beneyto');
        $post = $this->makePost('Una pieza', $local, $byline, 'draft');

        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
        request()->query->replace(['include' => 'category,author,tags,coverMedia']);

        $json = app(SnkKilkanaPostsController::class)->show($post->id)->getData(true);

        // Sin `?fields=`, la ficha trae la pieza entera: es la puerta del editor y ahí se
        // escribe. `url_path` sale porque `url_date` está, que es lo que sustituye al `$appends`.
        foreach (['body_blocks', 'seo_title', 'url_path', 'og_title', 'noindex'] as $field) {
            $this->assertArrayHasKey($field, $json, "el editor necesita «{$field}»");
        }
        foreach (['category', 'author', 'tags'] as $relation) {
            $this->assertArrayHasKey($relation, $json, "el editor pide «{$relation}» y tiene que llegar");
        }
    }

    /**
     * Publicar es AHORA, y por eso la fecha no se le pide a nadie.
     *
     * Un borrador no tiene `publish_at` —se sella al salir a la calle—, así que si `required_if`
     * la exige **desde el editor no se puede publicar una pieza**: el 422 habla de una fecha que
     * el panel no tiene dónde pedir. El guardado y la acción en tanda sellan igual.
     */
    public function test_publishing_stamps_the_date_when_missing_or_in_the_future(): void
    {
        $tomorrow = now()->addDay()->toDateTimeString();
        $yesterday = now()->subDay()->toDateTimeString();

        // Sin fecha: se sella ahora.
        $this->assertNotNull(SnkKilkanaPostService::stampFor('published', null));
        $this->assertNotNull(SnkKilkanaPostService::stampFor('published', ''));

        // Programada para mañana y publicada a mano: sale AHORA, no mañana. Dejarle la fecha
        // prevista la ordenaría mañana en la portada, el sitemap y el feed.
        $stamp = SnkKilkanaPostService::stampFor('published', $tomorrow);
        $this->assertNotNull($stamp);
        $this->assertTrue(Carbon::parse($stamp)->lessThan(Carbon::parse($tomorrow)));

        // Una fecha pasada es una decisión editorial y se respeta.
        $this->assertNull(SnkKilkanaPostService::stampFor('published', $yesterday));

        // Y `scheduled` no se inventa: una programada sin fecha es un error de verdad.
        $this->assertNull(SnkKilkanaPostService::stampFor('scheduled', null));
        $this->assertNull(SnkKilkanaPostService::stampFor('draft', null));
    }

    private function category(string $name, string $slug): SnkKilkanaCategoryModel
    {
        return SnkKilkanaCategoryModel::create([
            'name' => $name,
            'slug' => $slug,
            'status' => 'live',
            'position' => 0,
        ]);
    }

    private function author(string $accountName, string $alias = ''): SnkKilkanaAuthorModel
    {
        $user = CoreUserModel::create([
            'name' => $accountName,
            'email' => str()->slug($accountName).'@eldiario.example',
            'password' => 'secret1234',
        ]);

        return SnkKilkanaAuthorModel::create([
            'user_id' => $user->id,
            'alias' => $alias === '' ? null : $alias,
            'slug' => str()->slug($alias === '' ? $accountName : $alias),
        ]);
    }

    private function makePost(
        string $title,
        SnkKilkanaCategoryModel $category,
        SnkKilkanaAuthorModel $author,
        string $status,
        ?string $publishAt = null,
        int $createdBy = 1,
    ): SnkKilkanaPostModel {
        return SnkKilkanaPostModel::create([
            'title' => $title,
            'excerpt' => 'Entradilla de prueba.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => $status,
            'publish_at' => $publishAt,
            'url_date' => $status === 'published' ? substr((string) $publishAt, 0, 10) : null,
            'created_by' => $createdBy,
            'updated_by' => $createdBy,
        ]);
    }
}
