<?php

namespace Tests\Unit;

use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Services\SnkKilkanaPostService;
use PHPUnit\Framework\TestCase;

/**
 * Los doce campos de la fila del listado, escritos a mano en este lado.
 *
 * Es la gemela de `api.server.test.ts` en `kilkana.app`, que escribe la misma lista y ademas
 * comprueba con un Proxy que el parser del BFF lee exactamente esas claves. **El acoplamiento
 * no lo puede ver ningun compilador**: aqui es un `setVisible()` y alli un parser, y entre los
 * dos hay una respuesta JSON.
 *
 * Ya divergio una vez: `FILA_DEL_LISTADO` dejo de publicar `seo_*`, `og_*` y `noindex`, y el
 * BFF siguio parseandolos sin que nada fallara —seis `undefined` por fila y un `noindex: false`
 * que afirmaba «indexable» sobre un dato que no habia llegado—. Esa es la clase de deriva que
 * esta prueba y su gemela existen para cazar el mismo dia.
 *
 * Si se pone roja, el arreglo no es cambiar la lista de aqui: es mirar el otro lado y decidir a
 * la vez. Es la misma regla que el vocabulario de bloques.
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

    public function test_la_fila_del_listado_publica_doce_campos(): void
    {
        $this->assertSame([
            'id',
            'title',
            'excerpt',
            'slug',
            'status',
            'content_tier',
            'publish_at',
            'views_total',
            'cover',
            'category',
            'author',
            'tags',
        ], SnkKilkanaPostService::FILA_DEL_LISTADO);
    }

    /**
     * Y lo que NO publica, dicho por su nombre.
     *
     * Sin esto, la prueba de arriba se «arregla» sola el dia que alguien anada un campo:
     * copiarlo a la lista de aqui la pone verde otra vez. Estos seis son los que ya se
     * colaron una vez, y el motivo por el que no vuelven es de contrato, no de olvido: el
     * listado dibuja una tabla y esos seis son la ficha de una pieza, que trae `GET posts/{id}`.
     */
    public function test_la_ficha_de_una_pieza_no_viaja_en_el_listado(): void
    {
        foreach (['seo_title', 'seo_description', 'og_title', 'og_description', 'og_image_id', 'noindex', 'body_blocks'] as $campo) {
            $this->assertNotContains(
                $campo,
                SnkKilkanaPostService::FILA_DEL_LISTADO,
                "«{$campo}» es de la ficha, no de la fila del listado",
            );
        }
    }
}
