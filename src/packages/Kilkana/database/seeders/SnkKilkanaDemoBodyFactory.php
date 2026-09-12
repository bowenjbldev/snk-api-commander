<?php

namespace Packages\Kilkana\Database\Seeders;

/**
 * El cuerpo de las piezas de demo, compuesto a partir de lo que el snapshot ya sabe de cada una.
 *
 * El snapshot trae titular, entradilla, sección, firma, foto y etiquetas, pero NO cuerpo, y sin
 * cuerpo media demo no significa nada: el artículo público sale en blanco, el editor abre una
 * pieza vacía y `content_tier` no corta por ningún sitio porque no hay muro que colocar. Escribir
 * 522 cuerpos a mano tampoco es la respuesta —envejecen sueltos del titular que acompañan—, así
 * que se componen aquí, con la entradilla real como arranque y prosa de relleno por detrás.
 *
 * **Determinista y sin azar**: todo lo que se elige sale de `crc32($slug)`, así que dos siembras
 * dan el mismo cuerpo y un diff de la base no se llena de ruido. Es la misma regla que siguen
 * `seedViews` y `seedComments`.
 *
 * **La prosa vive en `SnkKilkanaDemoProse`, por familia de sección** —ocho, y no una por
 * sección: `futbol` y `polideportivo` se leen igual de creíbles con el mismo material—, y hay
 * bastante para que una pieza no repita un párrafo dentro de sí y dos piezas seguidas de la misma
 * sección casi no compartan ninguno. Lo específico de cada pieza sale de sus propios datos.
 *
 * El bloque es `{id, type, config}`, que es lo que valida el controlador y lo que lee
 * `publicBodyBlocks()`. Dentro de `config` van los campos del bloque del editor tal cual
 * —`text`, `source`, `items`, `caption`—, con una sola diferencia: la foto viaja como `media_id`,
 * la PK de verdad de `kilkana_media`, y no como el id de la mediateca de la fixture.
 */
final class SnkKilkanaDemoBodyFactory
{
    /**
     * A qué familia de prosa pertenece cada sección.
     *
     * Las secciones se reparten en ocho familias: una subsección se lee con el material de su
     * mesa, y separar `futbol` de `polideportivo` duplicaría el texto sin que nadie note la
     * diferencia. Una sección que no esté aquí cae en `local`, que es la mesa de un diario
     * comarcal y el relleno menos llamativo.
     *
     * Una sección con ruta retirada entra con los dos slugs: el snapshot trae el retirado en
     * `cat` —`sucesos`, con el que se compone el cuerpo— y la base guarda el del catálogo
     * —`sucesos-y-tribunales`, con el que se eligen las voces de los comentarios—.
     */
    public const FAMILY_OF = [
        'local' => 'local', 'comarca' => 'local', 'municipios' => 'local', 'barrios' => 'local',
        'politica' => 'politics',
        'economia' => 'economy', 'agricultura' => 'economy', 'empleo' => 'economy',
        'deportes' => 'sports', 'futbol' => 'sports', 'polideportivo' => 'sports',
        'cultura' => 'culture', 'patrimonio' => 'culture', 'agenda' => 'culture',
        'sucesos' => 'crime', 'sucesos-y-tribunales' => 'crime',
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

        // Siete párrafos distintos de la familia, recorridos desde un arranque y con un paso
        // primo con el tamaño del pozo: así no se repite ninguno dentro de la pieza y dos slugs
        // distintos arrancan en sitios distintos. Siete y no menos porque con la entradilla y
        // el cierre salen unas trescientas palabras, que es un artículo corto.
        $prose = self::pickDistinct($material['prose'], $hash, 7);
        [$subheading, $secondSubheading] = self::pickDistinct($material['subheadings'], intdiv($hash, 3), 2);
        [$quote, $who] = $pick($material['quotes'], 1);

        $blocks = [
            // La entradilla ES el primer párrafo. En un periódico lo es: el resumen que se lee en
            // la portada y la entrada de la pieza son el mismo texto, y separarlos aquí obligaría
            // a escribir 522 arranques que dijeran lo mismo con otras palabras.
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

        // Una de cada tres lleva recuadro de claves, y solo si tiene de qué hacerlo: las
        // etiquetas de la pieza. Ponerlo en todas lo convertiría en decoración fija, y la
        // audiencia no entra: ningún periódico imprime sus analíticas dentro del artículo.
        if ($hash % 3 === 0 && count($post['tags'] ?? []) >= 2) {
            $blocks[] = self::textBlock('factbox', 'Claves · '.implode(' · ', array_map(ucfirst(...), array_slice($post['tags'], 0, 3))));
        }

        $blocks[] = self::textBlock('paragraph', $prose[4]);
        $blocks[] = self::textBlock('heading', $secondSubheading);
        $blocks[] = self::textBlock('paragraph', $prose[5]);
        $blocks[] = self::textBlock('paragraph', $prose[6]);
        // El cierre es de la familia y no una plantilla con la etiqueta dentro, que se leería
        // igual en las 522 piezas.
        $blocks[] = self::textBlock('paragraph', $pick($material['closings'], 5));

        // El muro va DENTRO del cuerpo y no al final, que es lo único que lo hace significar algo:
        // `publicBodyBlocks()` corta ahí, así que su posición es literalmente cuánto se lee gratis.
        // Una pieza `locked` enseña el arranque; una `metered`, casi toda menos el remate.
        $cutoff = match ($post['content_tier']) {
            'locked' => 3,
            'metered' => count($blocks) - 1,
            default => null,
        };
        if ($cutoff !== null) {
            array_splice($blocks, $cutoff, 0, [['type' => 'paywall', 'config' => []]]);
        }

        // Los relacionados rematan la pieza, y solo la publicada: en un borrador no hay archivo
        // al que enlazar todavía.
        if ($post['status'] === 'published') {
            $blocks[] = ['type' => 'divider', 'config' => []];
            $blocks[] = ['type' => 'related', 'config' => []];
        }

        // Los ids se numeran al final y no al construir: insertar el muro por el medio dejaría
        // la numeración saltada, y el editor arranca su contador detrás del mayor id.
        return array_values(array_map(
            fn (array $block, int $n) => ['id' => 'b'.($n + 1)] + $block,
            $blocks,
            array_keys($blocks),
        ));
    }

    /** La familia de prosa de una sección; una que no esté en la tabla cae en `local`. */
    public static function familyOf(string $cat): string
    {
        return self::FAMILY_OF[$cat] ?? 'local';
    }

    /**
     * `$n` elementos distintos del pozo, deterministas por el hash.
     *
     * Arranque `hash % tamaño` y paso elegido entre primos que no dividan al tamaño: recorre el
     * pozo sin repetir, y dos piezas con hash distinto arrancan en sitios distintos. Con un pozo
     * más corto que `$n`, devuelve el pozo entero.
     *
     * **Los pasos tienen que ser primos.** «No divide al tamaño» equivale a «coprimo con el
     * tamaño» solo para un primo, y coprimo es lo que garantiza que el paseo no vuelva a un
     * párrafo ya visto: un 9 o un 21 «para más variedad» pasarían el filtro y repetirían.
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
