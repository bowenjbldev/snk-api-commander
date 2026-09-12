<?php

namespace Tests\Unit;

use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Services\SnkKilkanaPostService;
use Tests\TestCase;

/**
 * Las dos reglas que decide una edición, ahora sin petición delante.
 *
 * Vivían dentro de `update()` y por eso solo se podían ejercitar montando un `Request`, un
 * usuario y un permiso. Son reglas del agregado —«desde qué versión escribes» y «qué significa
 * una edición parcial que publica»— y aquí se prueban por lo que son.
 *
 * No toca la base: los dos métodos son estáticos y trabajan sobre un modelo en memoria.
 */
class SnkKilkanaPostWriteRulesTest extends TestCase
{
    public function test_an_unreadable_value_counts_as_stale_and_a_missing_one_does_not(): void
    {
        $post = new SnkKilkanaPostModel(['updated_at' => '2026-06-01 10:00:00']);

        foreach ([null, '', 0, []] as $empty) {
            $this->assertFalse(
                SnkKilkanaPostService::staleFor($post, $empty),
                'Quien no dice desde qué versión escribe no está desfasado: '.var_export($empty, true),
            );
        }

        // La misma versión en otra variante ISO **no** es un conflicto: se comparan instantes y
        // no cadenas, o un cambio de serializador convertiría todos los guardados en un 409.
        $this->assertFalse(SnkKilkanaPostService::staleFor($post, '2026-06-01T10:00:00.000000Z'));
        $this->assertFalse(SnkKilkanaPostService::staleFor($post, '2026-06-01 10:00:00'));

        $this->assertTrue(SnkKilkanaPostService::staleFor($post, '2026-05-31 10:00:00'), 'Una versión vieja sí.');
        // Y lo que no se puede leer también: quien manda basura no puede acabar pisando lo que
        // otro escribió.
        $this->assertTrue(SnkKilkanaPostService::staleFor($post, 'banana'));
        $this->assertTrue(SnkKilkanaPostService::staleFor(new SnkKilkanaPostModel, '2026-06-01 10:00:00'), 'Sin fecha en la fila, tampoco.');
    }

    public function test_publishing_fills_from_the_row_and_stamps_now(): void
    {
        $draft = new SnkKilkanaPostModel(['excerpt' => 'La de la fila', 'publish_at' => null]);

        // Una edición que no publica no toca nada.
        $this->assertSame(['title' => 'Otro'], SnkKilkanaPostService::publishDefaults($draft, ['title' => 'Otro']));

        $publishing = SnkKilkanaPostService::publishDefaults($draft, ['status' => 'published']);

        $this->assertSame('La de la fila', $publishing['excerpt'], 'La entradilla que la pieza ya tiene no se le pide otra vez.');
        $this->assertNotNull($publishing['publish_at'], 'Publicar es AHORA: un borrador no tiene fecha y se le sella.');

        // Lo que llega gana a lo de la fila; una entradilla vacía a propósito sigue siendo un 422
        // más adelante, y por eso no se rellena.
        $this->assertSame('', SnkKilkanaPostService::publishDefaults($draft, ['status' => 'published', 'excerpt' => ''])['excerpt']);

        // Programar no inventa fecha: una programada sin ella es un error de verdad.
        $this->assertArrayNotHasKey('publish_at', SnkKilkanaPostService::publishDefaults($draft, ['status' => 'scheduled']));
    }
}
