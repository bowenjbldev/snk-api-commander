<?php

namespace Tests\Unit;

use Packages\Kilkana\Services\SnkKilkanaPublicService;
use PHPUnit\Framework\TestCase;

/**
 * El techo de lo que la calle puede pedir, que es lo único que sigue siendo una constante.
 *
 * Aquí vivían además los doce campos de la fila del listado, escritos a mano en este lado y
 * copiados a mano en el parser del BFF. Ya divergieron una vez: `FILA_DEL_LISTADO` dejó de
 * publicar `seo_*`, `og_*` y `noindex`, y el frontend siguió parseándolos sin que nada fallara
 * —seis `undefined` por fila y un `noindex: false` que afirmaba «indexable» sobre un dato que no
 * había llegado—.
 *
 * Esa pareja ya no existe: la lista la manda quien llama con `?fields=` y el servidor solo
 * declara el techo. Lo que la sustituye es
 * `KilkanaPostsListTest::test_la_fila_trae_las_columnas_que_se_piden_y_ninguna_mas`, que es una
 * ida y vuelta por el endpoint en vez de dos listas comparadas de lejos.
 */
class SnkKilkanaPostsListRowTest extends TestCase
{
    /**
     * El techo de la calle no puede crecer por descuido.
     *
     * `SnkKilkanaPublicService::FIELDS` es lo que un lector anónimo puede pedir con `?fields=`,
     * y añadir una entrada ahí es una decisión de privacidad, no de comodidad: son las columnas
     * que dicen quién escribió, quién editó y quién tiró una pieza, más el cuerpo entero, que en
     * la calle lo sirve `publicBodyBlocks()` para que el muro se aplique.
     *
     * Si esta prueba se pone roja, el arreglo no es ampliar la lista de abajo.
     */
    public function test_la_calle_no_puede_pedir_lo_que_no_es_suyo(): void
    {
        foreach (['created_by', 'updated_by', 'deleted_at', 'deleted_by', 'body_blocks'] as $columna) {
            $this->assertNotContains($columna, SnkKilkanaPublicService::FIELDS, "`{$columna}` no sale a la calle.");
        }
    }

    /**
     * Las llaves foráneas están, porque sin ellas la relación llega nula **y sin ruido**.
     *
     * Aquí vivía `COLUMNS_FOR`, un mapa de campo derivado a columnas de las que dependía. Ya no
     * hace falta para los derivados —el Resource solo emite `url_path` si `url_date` está—, pero
     * sí para las relaciones: un `belongsTo` empareja por la clave de la fila, y si el recorte
     * se la lleva, Eloquent devuelve `null` sin avisar. Quien pueda incluir una relación tiene
     * que poder pedir su llave.
     */
    public function test_la_calle_puede_pedir_las_llaves_de_lo_que_incluye(): void
    {
        foreach (['category_id', 'author_id', 'cover_media_id'] as $llave) {
            $this->assertContains($llave, SnkKilkanaPublicService::FIELDS, "sin `{$llave}` la relación llega nula");
        }
    }
}
