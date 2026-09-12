<?php

namespace Packages\Kilkana\Database\Seeders;

/**
 * El cuerpo de las piezas de demo, compuesto a partir de lo que el snapshot ya sabe de cada una.
 *
 * El snapshot trae titular, entradilla, seccion, firma, foto y etiquetas, pero NO cuerpo, y sin
 * cuerpo media demo no significa nada: el articulo publico sale en blanco, el editor abre una
 * pieza vacia y `content_tier` no corta por ningun sitio porque no hay muro que colocar. Escribir
 * 522 cuerpos a mano tampoco es la respuesta —envejecen sueltos del titular que acompanan—, asi
 * que se componen aqui, con la entradilla real como arranque y prosa de relleno por detras.
 *
 * **Determinista y sin azar**: todo lo que se elige sale de `crc32($slug)`, asi que dos siembras
 * dan el mismo cuerpo y un diff de la base no se llena de ruido. Es la misma regla que ya siguen
 * `seedViews` y `seedComments`.
 *
 * **La prosa vive en `SnkKilkanaDemoProse`, por familia de seccion** —ocho, no dieciseis:
 * `futbol` y `polideportivo` se leen igual de creibles con el mismo material—, y hay bastante
 * para que una pieza no repita un parrafo dentro de si y dos piezas seguidas de la misma seccion
 * casi no compartan ninguno. Lo especifico de cada pieza sale de sus propios datos.
 *
 * El bloque es `{id, type, config}`, que es lo que valida el controlador y lo que lee
 * `publicBodyBlocks()`. Dentro de `config` van los campos del bloque del editor tal cual
 * —`text`, `source`, `items`, `caption`—, con una sola diferencia: la foto viaja como `media_id`,
 * la PK de verdad de `kilkana_media`, y no como el id de la mediateca de la fixture.
 */
final class SnkKilkanaDemoBodyFactory
{
    /**
     * A que familia de prosa pertenece cada seccion.
     *
     * Las dieciseis secciones se reparten en ocho: una subseccion se lee con el material de su
     * mesa, y separar `futbol` de `polideportivo` habria duplicado el texto sin que nadie note la
     * diferencia. Una seccion que no este aqui cae en `local`, que es la mesa de un diario
     * comarcal y el relleno menos llamativo.
     */
    public const FAMILY_OF = [
        'local' => 'local', 'comarca' => 'local', 'municipios' => 'local', 'barrios' => 'local',
        'politica' => 'politics',
        'economia' => 'economy', 'agricultura' => 'economy', 'empleo' => 'economy',
        'deportes' => 'sports', 'futbol' => 'sports', 'polideportivo' => 'sports',
        'cultura' => 'culture', 'patrimonio' => 'culture', 'agenda' => 'culture',
        'sucesos' => 'crime',
        'opinion' => 'opinion',
        'medio-ambiente' => 'environment',
    ];

    /**
     * El cuerpo de una pieza del snapshot.
     *
     * @param  array<string, mixed>  $post  la fila del snapshot
     * @param  int|null  $coverId  la PK de la portada ya sembrada, si la pieza trae foto
     * @return array<int, array{id: string, type: string, config: array<string, mixed>}>
     */
    public static function blocks(array $post, ?int $coverId): array
    {
        $family = self::familyOf((string) $post['cat']);
        $material = SnkKilkanaDemoProse::MATERIAL[$family];
        $hash = crc32((string) $post['slug']);
        $pick = fn (array $pool, int $offset) => $pool[intdiv($hash, $offset) % count($pool)];

        // Siete parrafos distintos de la familia, recorridos desde un arranque y con un paso
        // primos entre si con el tamaño del pozo: asi no se repite ninguno dentro de la pieza y
        // dos slugs distintos arrancan en sitios distintos. Siete y no menos porque con la
        // entradilla y el cierre salen unas trescientas palabras, que es un articulo corto.
        $prose = self::pickDistinct($material['prose'], $hash, 7);
        [$subheading, $secondSubheading] = self::pickDistinct($material['subheadings'], intdiv($hash, 3), 2);
        [$quote, $who] = $pick($material['quotes'], 1);

        $blocks = [
            // La entradilla ES el primer parrafo. En un periodico lo es: el resumen que se lee en
            // la portada y la entrada de la pieza son el mismo texto, y separarlos aqui habria
            // obligado a escribir 522 arranques que dijeran lo mismo con otras palabras.
            self::textBlock('paragraph', $post['excerpt']),
            self::textBlock('paragraph', $prose[0]),
            self::textBlock('paragraph', $prose[1]),
            self::textBlock('heading', $subheading),
        ];

        if ($coverId !== null) {
            $blocks[] = ['type' => 'image', 'config' => [
                'media_id' => $coverId,
                // El pie es la primera frase de la entradilla: describe lo que la foto
                // acompaña sin repetir el titular que está dos líneas más arriba.
                'caption' => self::firstSentence($post['excerpt']),
            ]];
        }

        $blocks[] = self::textBlock('paragraph', $prose[2]);
        $blocks[] = ['type' => 'quote', 'config' => ['text' => $quote, 'source' => $who]];
        $blocks[] = self::textBlock('paragraph', $prose[3]);

        // Una de cada tres lleva recuadro de claves, y solo si tiene de que hacerlo: las
        // etiquetas de la pieza. Ponerlo en todas lo convertiria en decoracion fija, y la
        // audiencia no entra: ningun periodico imprime sus analiticas dentro del articulo.
        if ($hash % 3 === 0 && count($post['tags'] ?? []) >= 2) {
            $blocks[] = self::textBlock('factbox', 'Claves · '.implode(' · ', array_map(ucfirst(...), array_slice($post['tags'], 0, 3))));
        }

        $blocks[] = self::textBlock('paragraph', $prose[4]);
        $blocks[] = self::textBlock('heading', $secondSubheading);
        $blocks[] = self::textBlock('paragraph', $prose[5]);
        $blocks[] = self::textBlock('paragraph', $prose[6]);
        // El cierre es de la familia y no una plantilla con la etiqueta dentro: «El seguimiento
        // de fiestas continúa» se leía en 522 piezas.
        $blocks[] = self::textBlock('paragraph', $pick($material['closings'], 5));

        // El muro va DENTRO del cuerpo y no al final, que es lo unico que lo hace significar algo:
        // `publicBodyBlocks()` corta ahi, asi que su posicion es literalmente cuanto se lee gratis.
        // Una pieza `locked` ensena el arranque; una `metered`, casi toda menos el remate.
        $cutoff = match ($post['content_tier']) {
            'locked' => 3,
            'metered' => count($blocks) - 1,
            default => null,
        };
        if ($cutoff !== null) {
            array_splice($blocks, $cutoff, 0, [['type' => 'paywall', 'config' => []]]);
        }

        // Los relacionados rematan la pieza, y solo la publicada: en un borrador no hay archivo
        // al que enlazar todavia.
        if ($post['status'] === 'published') {
            $blocks[] = ['type' => 'divider', 'config' => []];
            $blocks[] = ['type' => 'related', 'config' => []];
        }

        // Los ids se numeran al final y no al construir: insertar el muro por el medio habria
        // dejado la numeracion saltada, y el editor arranca su contador detras del mayor id.
        return array_values(array_map(
            fn (array $block, int $n) => ['id' => 'b'.($n + 1)] + $block,
            $blocks,
            array_keys($blocks),
        ));
    }

    /** La familia de prosa de una seccion; una que no este en la tabla cae en `local`. */
    public static function familyOf(string $cat): string
    {
        return self::FAMILY_OF[$cat] ?? 'local';
    }

    /**
     * `$n` elementos distintos del pozo, deterministas por el hash.
     *
     * Arranque `hash % tamaño` y paso elegido entre primos que no dividan al tamaño: recorre el
     * pozo sin repetir, y dos piezas con hash distinto arrancan en sitios distintos. Con un pozo
     * mas corto que `$n`, devuelve el pozo entero.
     *
     * **Los pasos tienen que ser primos.** «No divide al tamaño» equivale a «coprimo con el
     * tamaño» solo para un primo, y coprimo es lo que garantiza que el paseo no vuelva a un
     * parrafo ya visto: un 9 o un 21 «para mas variedad» pasarian el filtro y repetirian.
     *
     * @param  string[]  $pool
     * @return string[]
     */
    private static function pickDistinct(array $pool, int $hash, int $n): array
    {
        $size = count($pool);
        if ($size <= $n) {
            return array_values($pool);
        }
        $steps = array_values(array_filter([5, 7, 11, 13, 17, 19], fn (int $p) => $size % $p !== 0));
        $step = $steps[intdiv($hash, 97) % count($steps)];
        $start = $hash % $size;

        $output = [];
        for ($k = 0; $k < $n; $k++) {
            $output[] = $pool[($start + $k * $step) % $size];
        }

        return $output;
    }

    /** Hasta el primer punto, con el punto. Una entradilla sin punto se usa entera. */
    private static function firstSentence(string $text): string
    {
        $cutoff = mb_strpos($text, '. ');

        return $cutoff === false ? trim($text) : mb_substr($text, 0, $cutoff + 1);
    }

    /** @return array{type: string, config: array{text: string}} */
    private static function textBlock(string $type, string $text): array
    {
        return ['type' => $type, 'config' => ['text' => $text]];
    }
}
