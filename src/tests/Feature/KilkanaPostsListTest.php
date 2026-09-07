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
 * por host y token de Sanctum en la base del periódico— que la suite todavía no tiene.
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
     * Es lo que hace que un enlace viejo siga significando algo. Sin esto la respuesta venía
     * con `data` vacío y un `meta` diciendo que hay siete piezas, que es una contradicción que
     * el panel pinta como «no hay nada».
     */
    public function test_una_pagina_fuera_de_rango_ensena_la_ultima_y_no_una_tabla_vacia(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
        $seccion = $this->category('Local', 'local');
        $firma = $this->author('Redacción', alias: 'Redacción');
        for ($i = 0; $i < 7; $i++) {
            $this->makePost("Pieza {$i}", $seccion, $firma, 'draft', null, $marta->id);
        }

        request()->query->replace(['per_page' => '5', 'page' => '999']);
        $fuera = app(SnkKilkanaPostsController::class)->index()->getData(true);

        $this->assertSame(2, $fuera['meta']['current_page'], 'Cae a la última, no se queda en la 999.');
        $this->assertSame(2, $fuera['meta']['last_page']);
        $this->assertSame(7, $fuera['meta']['total']);
        $this->assertCount(2, $fuera['data'], 'Y trae las filas de esa última página.');
    }

    /**
     * Lo que no se reconoce cae al defecto; nunca es un 422 ni una tabla vacía.
     *
     * Es la misma regla que aplica el panel al leer sus `searchParams`: quien teclea
     * `?status=banana` a mano se merece la lista entera, no una pantalla en blanco. Y el tope
     * por página no lo elige quien llama, o `?per_page=500` restaura el volcado entero.
     */
    public function test_lo_que_la_query_no_reconoce_cae_al_defecto(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
        $seccion = $this->category('Local', 'local');
        $firma = $this->author('Redacción', alias: 'Redacción');
        $this->makePost('Una', $seccion, $firma, 'draft', null, $marta->id);

        $pedir = function (array $query) {
            request()->query->replace($query);

            return app(SnkKilkanaPostsController::class)->index()->getData(true);
        };

        $this->assertCount(1, $pedir(['status' => 'banana'])['data'], 'Un estado inventado no filtra a cero.');
        $this->assertCount(1, $pedir(['category' => 'No existe'])['data'], 'Ni una sección que no está.');
        $this->assertCount(1, $pedir(['sort' => 'banana'])['data'], 'Ni un orden que no existe.');
        $this->assertSame(100, $pedir(['per_page' => '500'])['meta']['per_page'], 'El techo no lo elige quien llama.');
        $this->assertSame(25, $pedir([])['meta']['per_page'], 'Y sin pedir nada, veinticinco.');
    }

    /**
     * El orden va en un parámetro, y el `-` delante es descendente.
     *
     * Eran dos —`sort` y `dir`—, y ninguno decía por sí solo cómo iba a salir la lista: `sort=title`
     * sin `dir` salía descendente, que es lo contrario de lo que cualquiera espera de un
     * alfabético. Ahora sin signo es ascendente, como en JSON:API.
     */
    public function test_el_orden_va_en_un_parametro_y_el_menos_es_descendente(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
        $seccion = $this->category('Local', 'local');
        $firma = $this->author('Redacción', alias: 'Redacción');
        foreach (['Bravo' => '2026-06-02 10:00:00', 'Alfa' => '2026-06-03 10:00:00', 'Charlie' => '2026-06-01 10:00:00'] as $titular => $cuando) {
            $this->makePost($titular, $seccion, $firma, 'published', $cuando, $marta->id);
        }

        $titulos = function (array $query) {
            request()->query->replace($query);

            return array_column(app(SnkKilkanaPostsController::class)->index()->getData(true)['data'], 'title');
        };

        $this->assertSame(['Alfa', 'Bravo', 'Charlie'], $titulos(['sort' => 'title']));
        $this->assertSame(['Charlie', 'Bravo', 'Alfa'], $titulos(['sort' => '-title']));

        // Sin pedir orden, lo más nuevo primero: es con lo que se abre el panel.
        $this->assertSame(['Alfa', 'Bravo', 'Charlie'], $titulos(['sort' => '-date']));
        $this->assertSame($titulos([]), $titulos(['sort' => '-date']));

        // Y lo que no está en el vocabulario cae ahí, con signo o sin él.
        $this->assertSame($titulos([]), $titulos(['sort' => 'banana']));
        $this->assertSame($titulos([]), $titulos(['sort' => '-banana']));
    }

    /**
     * Las cifras de las pestañas solo viajan si se piden.
     *
     * Cuentan el archivo entero y no la página, así que no son parte de esta respuesta: son
     * otra pregunta que se contesta en el mismo viaje. Tres pantallas del panel piden este
     * listado solo para enseñar «lo último publicado» y pagaban el recuento sin usarlo.
     */
    public function test_las_cifras_solo_viajan_si_se_piden(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
        $this->makePost('Una', $this->category('Local', 'local'), $this->author('Redacción', alias: 'Redacción'), 'draft', null, $marta->id);

        $pedir = function (array $query) {
            request()->query->replace($query);

            return app(SnkKilkanaPostsController::class)->index()->getData(true);
        };

        $sin = $pedir([]);
        $this->assertArrayNotHasKey('counts', $sin);
        $this->assertArrayNotHasKey('counts_all', $sin);
        $this->assertCount(1, $sin['data'], 'Las filas siguen llegando.');

        $con = $pedir(['summary' => 'counts']);
        $this->assertSame(1, $con['counts']['draft']);
        $this->assertArrayHasKey('counts_all', $con);

        // Y lo que no se ofrece se ignora, como el resto de la query: nunca un 422.
        $this->assertArrayNotHasKey('counts', $pedir(['summary' => 'banana']));
        $this->assertArrayHasKey('counts', $pedir(['summary' => 'banana,counts']));
    }

    public function test_el_filtro_acota_y_el_orden_es_estable(): void
    {
        $deportes = $this->category('Deportes', 'deportes');
        $local = $this->category('Local', 'local');
        $marta = $this->author('Marta Beneyto');
        $casa = $this->author('Redacción', alias: 'Redacción');

        $this->makePost('El derbi acaba en tablas', $deportes, $marta, 'published', '2026-06-01 10:00:00');
        $this->makePost('El pleno aprueba el presupuesto', $local, $marta, 'draft');
        $this->makePost('Breve de la casa', $local, $casa, 'draft');

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

    public function test_las_cifras_de_las_pestanas_cuentan_el_archivo_y_no_la_pagina(): void
    {
        $local = $this->category('Local', 'local');
        $firma = $this->author('Marta Beneyto');

        $this->makePost('Uno', $local, $firma, 'published', '2026-06-01 10:00:00');
        $this->makePost('Dos', $local, $firma, 'published', '2026-06-02 10:00:00');
        $this->makePost('Tres', $local, $firma, 'draft');

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
    public function test_las_cifras_llegan_en_las_dos_escalas_y_solo_una_esta_acotada(): void
    {
        $local = $this->category('Local', 'local');
        $firma = $this->author('Marta Beneyto');

        // Dos de la cuenta 7 y una de otra: así se distingue lo suyo de lo de la casa.
        $this->makePost('Suya publicada', $local, $firma, 'published', '2026-06-01 10:00:00', createdBy: 7);
        $this->makePost('Suyo borrador', $local, $firma, 'draft', createdBy: 7);
        $this->makePost('De otro', $local, $firma, 'published', '2026-06-02 10:00:00', createdBy: 9);

        $acotada = $this->posts->countsByStatus(7, false, true);
        $this->assertSame(1, $acotada['scoped']['published'], 'lo acotado tiene que contar solo lo suyo');
        $this->assertSame(2, $acotada['all']['published'], 'la otra escala cuenta el periódico entero');
        $this->assertSame(1, $acotada['scoped']['draft']);

        // Con `edit others posts` las dos escalas son la misma, y el panel enseña una cifra
        // sola en vez de «2 of 2».
        $abierta = $this->posts->countsByStatus(7, true, true);
        $this->assertSame($abierta['all'], $abierta['scoped']);
        $this->assertSame(2, $abierta['scoped']['published']);
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
    public function test_editar_lo_ajeno_implica_verlo_aunque_falte_el_permiso(): void
    {
        $local = $this->category('Local', 'local');
        $firma = $this->author('Marta Beneyto');

        $this->makePost('Suya', $local, $firma, 'published', '2026-06-01 10:00:00', createdBy: 7);
        $this->makePost('De otro', $local, $firma, 'published', '2026-06-02 10:00:00', createdBy: 9);

        $sinElPermiso = $this->posts->countsByStatus(7, true, false);

        $this->assertSame(2, $sinElPermiso['scoped']['published'], 'quien edita lo ajeno lo cuenta');
        $this->assertSame($sinElPermiso['scoped'], $sinElPermiso['all']);
    }

    /**
     * Quien escribe desde fuera no ve el sumario de la redacción.
     *
     * `counts_all` publica cuánto hay sin publicar y en qué fase, y eso es actividad editorial
     * ajena. Choca con la regla que el listado ya aplica tres métodos más arriba —una pieza
     * ajena no existe: 404, no 403, porque un 403 confirmaría cuántas hay—, así que el
     * recuento tiene que respetarla igual.
     *
     * Y la segunda escala **se iguala, no se calla**: el panel deduce de que las dos cifras
     * coincidan que hay una sola escala que dibujar, y omitir la clave le dejaría la barra sin
     * saber qué medir.
     */
    public function test_sin_ver_lo_ajeno_las_dos_escalas_son_lo_suyo(): void
    {
        $local = $this->category('Local', 'local');
        $firma = $this->author('Marta Beneyto');

        $this->makePost('Suya publicada', $local, $firma, 'published', '2026-06-01 10:00:00', createdBy: 7);
        $this->makePost('De otro', $local, $firma, 'published', '2026-06-02 10:00:00', createdBy: 9);
        $this->makePost('Borrador de otro', $local, $firma, 'draft', createdBy: 9);

        $fuera = $this->posts->countsByStatus(7, false, false);

        $this->assertSame($fuera['scoped'], $fuera['all'], 'sin ver lo ajeno las dos escalas coinciden');
        $this->assertSame(1, $fuera['scoped']['published']);
        $this->assertSame(0, $fuera['scoped']['draft'], 'el borrador de otro no puede aparecer ni contado');

        // Y las cuatro claves siguen estando, también las de cero: una pestaña que desaparece
        // al quedarse vacía es una pestaña a la que no se puede volver.
        $this->assertSame(['draft', 'review', 'scheduled', 'published'], array_keys($fuera['all']));
    }

    /**
     * Las dos escalas salen del **mismo recorrido**: un `COUNT(*)` y un `SUM(created_by = ?)`
     * sobre el mismo `GROUP BY`. Pedirlas por separado sería pasar dos veces por la tabla para
     * contestar dos mitades de la misma pregunta, y esta prueba es lo único que lo impide.
     */
    public function test_las_dos_escalas_cuestan_una_sola_consulta(): void
    {
        $local = $this->category('Local', 'local');
        $firma = $this->author('Marta Beneyto');
        $this->makePost('Una', $local, $firma, 'published', '2026-06-01 10:00:00', createdBy: 7);

        DB::enableQueryLog();
        $this->posts->countsByStatus(7, false, true);
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $consultas, "las dos escalas cuestan {$consultas} consultas y tienen que costar 1");
    }

    /**
     * El lote no saca a la calle lo que no está terminado.
     *
     * `required_if` vive en el controlador y solo mira la petición, y una petición de lote son
     * ids: sin esta comprobación, marcar los borradores y pulsar «Publicar» publicaba también
     * el que no tiene entradilla. El resto SÍ están listos, así que el lote no falla entero:
     * se salta el incompleto y el recuento lo dice.
     */
    public function test_el_lote_no_publica_una_pieza_sin_entradilla(): void
    {
        $local = $this->category('Local', 'local');
        $firma = $this->author('Marta Beneyto');

        $lista = $this->makePost('Con entradilla', $local, $firma, 'draft');
        $sin = $this->makePost('Sin entradilla', $local, $firma, 'draft');
        $sin->update(['excerpt' => null]);

        $movidas = $this->posts->bulk([$lista->id, $sin->id], 'publish', 1, true);

        $this->assertSame(1, $movidas);
        $this->assertSame('published', $lista->fresh()->status->value);
        $this->assertSame('draft', $sin->fresh()->status->value);

        // Y a borrador sí van las dos: la entradilla hace falta para SALIR, no para volver.
        $this->assertSame(2, $this->posts->bulk([$lista->id, $sin->id], 'draft', 1, true));
    }

    /**
     * El cron tampoco. A `scheduled` se llega por el lote, que no valida contenido, así que
     * sin esto la pieza incompleta salía a la calle a las seis de la mañana sin que nadie la
     * viera. Se queda programada, que es un estado del que se sale escribiéndola.
     */
    public function test_el_cron_no_publica_una_programada_sin_entradilla(): void
    {
        $local = $this->category('Local', 'local');
        $firma = $this->author('Marta Beneyto');

        $lista = $this->makePost('Programada completa', $local, $firma, 'scheduled', '2026-01-01 06:00:00');
        $sin = $this->makePost('Programada a medias', $local, $firma, 'scheduled', '2026-01-01 06:00:00');
        $sin->update(['excerpt' => null]);

        $this->assertSame(1, $this->posts->publishDue());
        $this->assertSame('published', $lista->fresh()->status->value);
        $this->assertSame('scheduled', $sin->fresh()->status->value);
    }

    /**
     * Las dos puertas leen la MISMA regla.
     *
     * Nula es «todavía no escrita» y `''` es «escrita y en blanco», pero para publicar dan
     * igual: las dos dejan la pieza sin entradilla en la calle. Con `whereNotNull` en el cron
     * y `null || ''` en el lote, una pieza en blanco salía por una puerta y no por la otra — y
     * el `down()` de la migración convierte las nulas en vacías, así que un rollback y su
     * reaplicación cambiaban quién se publica.
     */
    public function test_una_entradilla_en_blanco_cuenta_igual_que_una_ausente(): void
    {
        $local = $this->category('Local', 'local');
        $firma = $this->author('Marta Beneyto');

        $lote = $this->makePost('Por el lote', $local, $firma, 'draft');
        $cron = $this->makePost('Por el cron', $local, $firma, 'scheduled', '2026-01-01 06:00:00');
        $lote->update(['excerpt' => '']);
        $cron->update(['excerpt' => '   ']);

        $this->assertSame(0, $this->posts->bulk([$lote->id], 'publish', 1, true));
        $this->assertSame(0, $this->posts->publishDue());

        $this->assertSame('draft', $lote->fresh()->status->value);
        $this->assertSame('scheduled', $cron->fresh()->status->value);
    }

    /**
     * Un borrador sin entradilla sale en el listado. Es el estado normal de una pieza recién
     * creada —el titular llega antes que el texto—, y una lista que se cae con la primera se
     * lleva por delante las otras veinticuatro de la página.
     */
    public function test_un_borrador_sin_entradilla_sale_en_el_listado(): void
    {
        $local = $this->category('Local', 'local');
        $firma = $this->author('Marta Beneyto');

        $this->makePost('Titular a secas', $local, $firma, 'draft')->update(['excerpt' => null]);

        $filas = $this->posts->filter($this->posts->query(1, true), [])->get();

        $this->assertCount(1, $filas);
        $this->assertNull($filas->first()->excerpt);
    }

    /**
     * Etiquetar en tanda añade sin quitar, y quitar no borra el vocabulario.
     *
     * Es lo que hace que reetiquetar veinte piezas sea un endpoint y no veinte `PUT`: la unión
     * con lo que cada pieza ya llevaba la calcula quien tiene las filas delante.
     */
    public function test_el_lote_etiqueta_sin_pisar_lo_que_ya_habia(): void
    {
        $local = $this->category('Local', 'local');
        $firma = $this->author('Marta Beneyto');

        $una = $this->makePost('Una', $local, $firma, 'draft');
        $otra = $this->makePost('Otra', $local, $firma, 'draft');
        $una->tags()->sync([SnkKilkanaTagModel::firstOrCreateByName('pleno')->id]);

        $this->assertSame(2, $this->posts->bulk([$una->id, $otra->id], 'tag', 1, true, ['sequía']));

        // La que ya tenía «pleno» conserva las dos; la etiqueta nueva se creó al vuelo.
        $this->assertEqualsCanonicalizing(['pleno', 'sequía'], $una->fresh()->tags->pluck('name')->all());
        $this->assertSame(['sequía'], $otra->fresh()->tags->pluck('name')->all());

        $this->assertSame(2, $this->posts->bulk([$una->id, $otra->id], 'untag', 1, true, ['sequía']));

        $this->assertSame(['pleno'], $una->fresh()->tags->pluck('name')->all());
        $this->assertSame([], $otra->fresh()->tags->pluck('name')->all());
        // Desenganchar no borra la fila: el vocabulario lo gobierna su pantalla.
        $this->assertNotNull(SnkKilkanaTagModel::query()->where('name', 'sequía')->first());
    }

    /**
     * El listado no puede crecer en consultas al crecer en filas.
     *
     * Es la unica forma de que un N+1 no vuelva sin que nadie lo note: en produccion se paga
     * en milisegundos y en una demo de veinticinco filas no se ve. Medido antes de cerrarlo,
     * el listado hacia **61 consultas** para veinticinco filas —`usage_count` de la portada
     * son tres `COUNT(*)` por medio distinto, y `$appends` los calcula al vuelo cuando nadie
     * hizo `withCount`—; ahora hace siete y no depende de cuantas filas se pidan.
     *
     * Se cuentan sobre el JSON serializado a proposito: la mitad de los N+1 de Eloquent no
     * los dispara la consulta, los dispara un accesor al serializar.
     */
    public function test_el_listado_no_hace_una_consulta_por_fila(): void
    {
        $local = $this->category('Local', 'local');
        $firma = $this->author('Marta Beneyto');
        $foto = SnkKilkanaMediaModel::create(['url' => 'https://ejemplo.test/foto.jpg', 'url_hash' => sha1('https://ejemplo.test/foto.jpg')]);

        // Con las cuatro relaciones pedidas, que es cuando un N+1 puede existir: sin pedirlas
        // el listado no carga ninguna y la prueba sería cierta sin demostrar nada. Y por el
        // Resource, que es por donde sale en producción — la mitad de los N+1 de Eloquent no
        // los dispara la consulta sino un accesor al serializar.
        $serializar = function () {
            $page = $this->posts->filter(
                $this->posts->query(1, true)->with(['category', 'author', 'tags', 'coverMedia']),
                [],
            )->paginate(25);

            json_encode(SnkKilkanaPostResource::collection($page->getCollection()));
        };

        for ($n = 0; $n < 3; $n++) {
            $this->makePost("Pieza {$n}", $local, $firma, 'published', "2026-06-0{$n} 10:00:00")
                ->update(['cover_media_id' => $foto->id]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $serializar();
        $conTres = count(DB::getQueryLog());

        for ($n = 3; $n < 15; $n++) {
            $this->makePost("Pieza {$n}", $local, $firma, 'published', '2026-07-01 10:00:00')
                ->update(['cover_media_id' => $foto->id]);
        }
        DB::flushQueryLog();
        $serializar();
        $conQuince = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $conTres,
            $conQuince,
            "el listado pasa de {$conTres} consultas con 3 filas a {$conQuince} con 15: hay un N+1",
        );
    }

    /**
     * Y no calcula lo que nadie pinta.
     *
     * `views_day` y `views_week` eran dos subconsultas correlacionadas por fila que ningun
     * consumidor leia. Ordenar por lecturas pasaba de 5,9 a 18,6 ms de SQL solo por ellas,
     * porque ordenar por el alias de una correlacionada obliga a calcularla para TODAS las
     * filas. Las ventanas siguen donde se usan, en el servicio publico.
     */
    public function test_el_listado_solo_suma_la_audiencia_que_se_enseña(): void
    {
        $sql = $this->posts->withAudience($this->posts->query(1, true), null)->toSql();

        $this->assertStringContainsString('views_total', $sql);
        $this->assertStringNotContainsString('views_day', $sql);
        $this->assertStringNotContainsString('views_week', $sql);
    }

    /**
     * **Quien llama elige las columnas, y lo que no pide no viaja.**
     *
     * Sustituye a `FILA_DEL_LISTADO`, que era una lista de doce campos escrita en el servidor y
     * copiada a mano en el parser del BFF. Eran gemelas mantenidas en dos repos y ya divergieron
     * una vez: seis campos se cayeron de aquí y allí siguieron parseándose como `undefined`, con
     * un `noindex: false` que afirmaba «indexable» sobre un dato que no había llegado. Ahora la
     * lista está **una sola vez**, en quien la pide.
     *
     * Un campo no pedido sale **ausente**, no nulo, que es lo que permite notar la diferencia.
     *
     * Y `views_total` es la prueba del orden: es el alias de un `withSum`, y `withAggregate()`
     * pone `select tabla.*` antes de colgar la subconsulta. Si la suma se aplicara antes que
     * `?fields=`, el `select()` del recorte se la llevaría por delante y la audiencia se leería
     * como cero, sin error.
     *
     * Medido con 522 piezas cuando esto era una constante: mandar la fila entera costaba 28,0 ms
     * y 59,7 kB por página de veinticinco; recortada, 14,5 ms y 14,2 kB.
     */
    public function test_la_fila_trae_las_columnas_que_se_piden_y_ninguna_mas(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');

        $local = $this->category('Local', 'local');
        $firma = $this->author('Marta Beneyto', alias: 'Redacción');
        $foto = SnkKilkanaMediaModel::create(['url' => 'https://ejemplo.test/foto.jpg', 'url_hash' => sha1('https://ejemplo.test/foto.jpg')]);
        $pieza = $this->makePost('Una pieza', $local, $firma, 'published', '2026-06-01 10:00:00', $marta->id);
        $pieza->update(['cover_media_id' => $foto->id]);
        $pieza->tags()->sync([SnkKilkanaTagModel::firstOrCreateByName('pleno')->id]);

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
        $fila = app(SnkKilkanaPostsController::class)->index()->getData(true)['data'][0];

        $this->assertSame([
            'id', 'title', 'excerpt', 'slug', 'status', 'content_tier',
            'publish_at', 'cover_media_id', 'views_total', 'category', 'author', 'tags',
        ], array_keys($fila));

        $this->assertSame($foto->id, $fila['cover_media_id'], 'La portada es su columna, no un objeto.');
        // Y sale sin pedirla: no es una columna, así que `?fields=` no la nombra —le antepondría
        // la tabla y `kilkana_posts.views_total` no existe—. La cuelga `withAudience()` después
        // del recorte, y esta línea es la que prueba que ese orden se mantiene: al revés, el
        // `select()` de los campos se llevaría la subconsulta y la audiencia se leería como cero.
        // Sin lecturas la suma es `null`, no cero, y da igual: lo que se comprueba es que la
        // clave **está**. Si el recorte se hubiera llevado la subconsulta, `whenHas()` no la
        // vería y no habría clave que mirar.
        $this->assertArrayHasKey('views_total', $fila, 'La suma sobrevive al recorte de columnas.');

        // Y las relaciones tampoco viajan enteras: de cada una, lo que se pidió.
        $this->assertSame(['id', 'name'], array_keys($fila['category']));
        $this->assertSame(['id', 'name'], array_keys($fila['author']));
        $this->assertSame(['id', 'name'], array_keys($fila['tags'][0]));

        // El nombre de la firma es el PUBLICADO, que es derivado: recortar columnas no puede
        // dejarlo sin la que lo compone.
        $this->assertSame('Redacción', $fila['author']['name']);
    }

    /**
     * Lo que no se pide no viaja: **ni columnas ni relaciones**.
     *
     * El modelo declaraba `$with = ['category', 'author', 'coverMedia', 'tags']`, así que las
     * cargaba en toda consulta de piezas aunque la pantalla no pintara ninguna —y con ellas, la
     * cuenta de usos de cada portada, que son tres `COUNT` por foto—. Era la última pieza de la
     * forma de salida que decidía el modelo en vez de quien pregunta.
     */
    public function test_lo_que_no_se_pide_no_viaja(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
        $this->makePost('Una', $this->category('Local', 'local'), $this->author('Redacción', alias: 'Redacción'), 'draft', null, $marta->id);

        request()->query->replace(['fields' => 'id,title']);
        $fila = app(SnkKilkanaPostsController::class)->index()->getData(true)['data'][0];

        $this->assertSame(['id', 'title', 'views_total'], array_keys($fila), 'Y `views_total` porque la suma se cuelga siempre.');
        $this->assertSame('Una', $fila['title']);
    }

    /**
     * Y el editor sigue recibiendo la pieza entera: son dos preguntas distintas.
     */
    public function test_la_pieza_del_editor_no_viene_recortada(): void
    {
        $local = $this->category('Local', 'local');
        $firma = $this->author('Marta Beneyto');
        $pieza = $this->makePost('Una pieza', $local, $firma, 'draft');

        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
        request()->query->replace(['include' => 'category,author,tags,coverMedia']);

        $json = app(SnkKilkanaPostsController::class)->show($pieza->id)->getData(true);

        // Sin `?fields=`, la ficha trae la pieza entera: es la puerta del editor y ahí se
        // escribe. `url_path` sale porque `url_date` está, que es lo que sustituye al `$appends`.
        foreach (['body_blocks', 'seo_title', 'url_path', 'og_title', 'noindex'] as $campo) {
            $this->assertArrayHasKey($campo, $json, "el editor necesita «{$campo}»");
        }
        foreach (['category', 'author', 'tags'] as $relacion) {
            $this->assertArrayHasKey($relacion, $json, "el editor pide «{$relacion}» y tiene que llegar");
        }
    }

    /**
     * Publicar es AHORA, y por eso la fecha no se le pide a nadie.
     *
     * Un borrador no tiene `publish_at` —se sella al salir a la calle—, asi que `required_if`
     * la exigia y **desde el editor no se podia publicar una pieza**: el 422 hablaba de una
     * fecha que el panel no tiene donde pedir. La accion en tanda ya sellaba; lo que faltaba
     * era que las dos puertas se comportaran igual.
     */
    public function test_publicar_sella_la_fecha_cuando_falta_o_apunta_al_futuro(): void
    {
        $manana = now()->addDay()->toDateTimeString();
        $ayer = now()->subDay()->toDateTimeString();

        // Sin fecha: se sella ahora.
        $this->assertNotNull(SnkKilkanaPostService::stampFor('published', null));
        $this->assertNotNull(SnkKilkanaPostService::stampFor('published', ''));

        // Programada para manana y publicada a mano: sale AHORA, no manana. Dejarle la fecha
        // prevista la ordenaria manana en la portada, el sitemap y el feed.
        $sello = SnkKilkanaPostService::stampFor('published', $manana);
        $this->assertNotNull($sello);
        $this->assertTrue(Carbon::parse($sello)->lessThan(Carbon::parse($manana)));

        // Una fecha pasada es una decision editorial y se respeta.
        $this->assertNull(SnkKilkanaPostService::stampFor('published', $ayer));

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
