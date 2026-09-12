<?php

namespace Tests\Unit;

use Packages\Kilkana\Services\SnkKilkanaPublicService;
use PHPUnit\Framework\TestCase;

/**
 * El techo de lo que la calle puede pedir, que es lo único de la fila declarado como constante.
 *
 * Los campos de la fila del listado no se declaran en este lado: la lista la manda quien llama con
 * `?fields=` y el servidor solo declara el techo. Que la fila traiga exactamente lo que se pide lo
 * comprueba `KilkanaPostsListTest::test_the_row_includes_the_requested_columns_and_nothing_else`,
 * que es una ida y vuelta por el endpoint en vez de dos listas comparadas de lejos.
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
    public function test_the_street_cannot_request_what_is_not_its_own(): void
    {
        foreach (['created_by', 'updated_by', 'deleted_at', 'deleted_by', 'body_blocks'] as $column) {
            $this->assertNotContains($column, SnkKilkanaPublicService::FIELDS, "`{$column}` no sale a la calle.");
        }
    }

    /**
     * Las llaves foráneas están, porque sin ellas la relación llega nula **y sin ruido**.
     *
     * Los campos derivados no necesitan sus columnas —el Resource solo emite `url_path` si
     * `url_date` está—, pero las relaciones sí: un `belongsTo` empareja por la clave de la fila, y
     * si el recorte se la lleva, Eloquent devuelve `null` sin avisar. Quien pueda incluir una relación tiene
     * que poder pedir su llave.
     */
    public function test_the_street_can_request_the_keys_of_what_it_includes(): void
    {
        foreach (['category_id', 'author_id', 'cover_media_id'] as $foreignKey) {
            $this->assertContains($foreignKey, SnkKilkanaPublicService::FIELDS, "sin `{$foreignKey}` la relación llega nula");
        }
    }
}
