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
 * duplicados a la fuerza: PHP no puede leer un tipo de TypeScript. La duplicacion es
 * asumida; divergir no lo es, y por eso hay una prueba a cada lado con la MISMA lista
 * escrita a mano. La gemela es `src/lib/data/article.test.ts` en el frontend.
 *
 * Si esta prueba se pone roja, el arreglo no es cambiar la lista de aqui: es mirar el otro
 * lado y decidir a la vez. Los dos vocabularios estuvieron separados —siete tipos aqui,
 * diez alli— y el sintoma fue que el editor no podia guardar nada con un intertitulo.
 */
class SnkKilkanaBlockVocabularyTest extends TestCase
{
    public function test_los_diez_tipos_de_bloque_son_los_que_compone_el_editor(): void
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

    public function test_publicar_y_programar_son_los_que_sacan_la_pieza_a_la_calle(): void
    {
        // De aqui sale el `required_if` de la entradilla y la fecha: un borrador nace sin
        // ellas, y publicarlo asi es publicar media pieza.
        $this->assertSame(['scheduled', 'published'], SnkKilkanaPostStatusEnum::publishing());
    }

    /**
     * La FORMA de un bloque, escrita a mano desde este lado.
     *
     * Los diez nombres ya estan arriba, pero los nombres nunca fueron el problema: `type`
     * contra `kind` y anidado contra plano si. Una lista de nombres identica pasa en verde
     * mientras el editor pierde los pies de foto al guardar, porque el campo viaja con otra
     * clave. La gemela es `src/lib/piece.server.test.ts` en el frontend, que hace la ida y
     * vuelta con esta misma tabla.
     *
     * `media_id` es el unico campo que cambia de nombre entre los dos lados, y a proposito:
     * aqui es la PK de `kilkana_media` y alli todavia es el id de la mediateca de la fixture.
     */
    public function test_la_forma_de_un_bloque_es_id_type_y_config(): void
    {
        $contrato = [
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

        // Y lo que el seeder produce de verdad tiene que caber en ese contrato: es el unico
        // sitio de este repositorio que compone cuerpos, asi que si el contrato se mueve sin
        // que se mueva el, la demo entera se guarda con la forma vieja.
        $post = [
            'cat' => 'local',
            'slug' => 'una-pieza-cualquiera',
            'title' => 'Una pieza cualquiera',
            'excerpt' => 'Con su entradilla.',
            'status' => 'published',
            'content_tier' => 'metered',
            'tags' => ['pleno', 'movilidad'],
            'reads' => 1200,
        ];

        foreach (SnkKilkanaDemoBodyFactory::blocks($post, 9) as $bloque) {
            $this->assertSame(['id', 'type', 'config'], array_keys($bloque));
            $this->assertArrayHasKey($bloque['type'], $contrato);

            $claves = array_keys($bloque['config']);
            sort($claves);
            $this->assertSame(
                [],
                array_diff($claves, $contrato[$bloque['type']]),
                "el bloque «{$bloque['type']}» trae una clave que el contrato no tiene",
            );
        }
    }

    public function test_la_accion_masiva_se_traduce_a_un_estado(): void
    {
        $mapa = [];
        foreach (['publish', 'draft', 'review', 'scheduled', 'delete'] as $accion) {
            $mapa[$accion] = SnkKilkanaPostStatusEnum::fromBulkAction($accion)?->value;
        }

        $this->assertSame([
            'publish' => 'published',
            'draft' => 'draft',
            'review' => 'review',
            'scheduled' => 'scheduled',
            // Borrar no mueve de estado, asi que no tiene ninguno que devolver.
            'delete' => null,
        ], $mapa);
    }
}
