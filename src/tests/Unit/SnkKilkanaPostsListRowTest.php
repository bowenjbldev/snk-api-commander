<?php

namespace Tests\Unit;

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
