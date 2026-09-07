<?php

namespace Tests\Unit;

use Packages\Kilkana\Models\SnkKilkanaPostModel;
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
     * `SnkKilkanaPostModel::PUBLIC_FIELDS` es lo que un lector anónimo puede pedir con
     * `?fields=`, y añadir una entrada ahí es una decisión de privacidad, no de comodidad: son
     * las columnas que dicen quién escribió, quién editó y quién tiró una pieza, más el cuerpo
     * entero, que en la calle lo sirve `publicBodyBlocks()` para que el muro se aplique.
     *
     * Si esta prueba se pone roja, el arreglo no es ampliar la lista de abajo.
     */
    public function test_la_calle_no_puede_pedir_lo_que_no_es_suyo(): void
    {
        foreach (['created_by', 'updated_by', 'deleted_at', 'deleted_by', 'body_blocks'] as $columna) {
            $this->assertNotContains($columna, SnkKilkanaPostModel::PUBLIC_FIELDS, "`{$columna}` no sale a la calle.");
        }
    }

    /** Lo que no es una columna tiene que declarar de cuáles depende, o llega nulo sin decir por qué. */
    public function test_cada_campo_calculado_dice_de_que_columnas_depende(): void
    {
        foreach (['url_path', 'cover', 'category', 'author', 'tags', 'views_total', 'comments_count'] as $campo) {
            $this->assertContains($campo, SnkKilkanaPostModel::PUBLIC_FIELDS);
            $this->assertNotEmpty(
                SnkKilkanaPostModel::COLUMNS_FOR[$campo] ?? [],
                "`{$campo}` no es una columna y no dice de qué depende: pedirlo devolvería null.",
            );
        }
    }
}
