<?php

namespace Tests\Unit;

use Packages\Kilkana\Database\Seeders\SnkKilkanaDemoBodyFactory;
use Packages\Kilkana\Enums\SnkKilkanaBlockTypeEnum;
use Packages\Kilkana\Enums\SnkKilkanaPostStatusEnum;
use PHPUnit\Framework\TestCase;

/**
 * El vocabulario del cuerpo, escrito una vez a mano para que no pueda moverse solo.
 *
 * Los diez tipos son el contrato entre este backend y el editor de `kilkana.app`, y viven
 * duplicados a la fuerza: PHP no puede leer un tipo de TypeScript. La duplicación es
 * asumida; divergir no lo es, y por eso hay una prueba a cada lado con la MISMA lista
 * escrita a mano. La gemela es `src/lib/data/article.test.ts` en el frontend.
 *
 * Si esta prueba se pone roja, el arreglo no es cambiar la lista de aquí: es mirar el otro
 * lado y decidir a la vez. Con los dos vocabularios separados —un tipo que el editor compone y
 * este lado no conoce— el editor no puede guardar nada que lo lleve, un intertítulo por ejemplo.
 */
class SnkKilkanaBlockVocabularyTest extends TestCase
{
    public function test_the_ten_block_types_are_the_ones_the_editor_composes(): void
    {
        $this->assertSame([
            'paragraph',
            'heading',
            'image',
            'quote',
            'list',
            'embed',
            'divider',
            'paywall',
            'factbox',
            'related',
        ], SnkKilkanaBlockTypeEnum::values());
    }

    public function test_publish_and_schedule_are_the_statuses_that_put_a_post_on_the_street(): void
    {
        // De aquí sale el `required_if` de la entradilla y la fecha: un borrador nace sin
        // ellas, y publicarlo así es publicar media pieza.
        $this->assertSame(['scheduled', 'published'], SnkKilkanaPostStatusEnum::publishing());
    }

    /**
     * La FORMA de un bloque, escrita a mano desde este lado.
     *
     * Los diez nombres ya están arriba, pero los nombres no son el problema: `type`
     * contra `kind` y anidado contra plano sí. Una lista de nombres idéntica pasa en verde
     * mientras el editor pierde los pies de foto al guardar, porque el campo viaja con otra
     * clave. La gemela es `src/lib/piece.server.test.ts` en el frontend, que hace la ida y
     * vuelta con esta misma tabla.
     *
     * `media_id` es el único campo que cambia de nombre entre los dos lados, y a propósito:
     * aquí es la PK de `kilkana_media` y allí todavía es el id de la mediateca de la fixture.
     */
    public function test_a_block_shape_is_id_type_and_config(): void
    {
        $contract = [
            'paragraph' => ['text'],
            'heading' => ['text'],
            'image' => ['caption', 'media_id'],
            'quote' => ['source', 'text'],
            'list' => ['items'],
            'embed' => ['text'],
            'divider' => [],
            'paywall' => [],
            'factbox' => ['text'],
            'related' => [],
        ];

        // Y lo que el seeder produce de verdad tiene que caber en ese contrato: es el único
        // sitio de este repositorio que compone cuerpos, así que si el contrato se mueve sin
        // que se mueva él, la demo entera se guarda con la forma vieja.
        $post = [
            'category' => 'local',
            'slug' => 'una-pieza-cualquiera',
            'title' => 'Una pieza cualquiera',
            'excerpt' => 'Con su entradilla.',
            'status' => 'published',
            'content_tier' => 'metered',
            'tags' => ['pleno', 'movilidad'],
            'reads' => 1200,
        ];

        foreach (SnkKilkanaDemoBodyFactory::blocks($post, 9) as $block) {
            $this->assertSame(['id', 'type', 'config'], array_keys($block));
            $this->assertArrayHasKey($block['type'], $contract);

            $keys = array_keys($block['config']);
            sort($keys);
            $this->assertSame(
                [],
                array_diff($keys, $contract[$block['type']]),
                "el bloque «{$block['type']}» trae una clave que el contrato no tiene",
            );
        }
    }

    public function test_a_bulk_action_maps_to_a_status(): void
    {
        $map = [];
        foreach (['publish', 'draft', 'review', 'scheduled', 'delete'] as $action) {
            $map[$action] = SnkKilkanaPostStatusEnum::fromBulkAction($action)?->value;
        }

        $this->assertSame([
            'publish' => 'published',
            'draft' => 'draft',
            'review' => 'review',
            'scheduled' => 'scheduled',
            // Borrar no mueve de estado, así que no tiene ninguno que devolver.
            'delete' => null,
        ], $map);
    }
}
