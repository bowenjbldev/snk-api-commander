<?php

namespace Packages\Kilkana\Services;

use Packages\Kilkana\Enums\SnkKilkanaLayoutEnum;
use Packages\Kilkana\Models\SnkKilkanaThemeVariantModel;

/**
 * Lo que un tema tiene que cumplir para entrar en el catálogo.
 *
 * Existe porque los temas se van a producir deprisa y en cantidad, y un tema es exactamente el
 * tipo de cosa que sale preciosa y rompe la accesibilidad de un periódico entero: un gris
 * elegante sobre blanco puede no llegar al contraste mínimo y nadie lo nota mirando la captura.
 * Este producto tiene presupuestos Lighthouse de 100 y un gate que audita el HTML real, así que
 * la puerta de entrada del catálogo es el sitio barato para pararlo.
 *
 * Falla RUIDOSAMENTE y con el par de colores en la mano. Un catálogo que se sincroniza en
 * silencio descartando lo que no entiende acaba con temas a medias que nadie sabe por qué se
 * ven mal.
 */
class SnkKilkanaThemeSpecValidator
{
    /** Las cabeceras que sabe pintar el frontend. Es un componente, no un dato libre. */
    public const MASTHEADS = ['compact', 'daily'];

    /**
     * Los tipos de bloque del editor: las doce filas de portada y los diez del cuerpo.
     *
     * Duplica la lista de `kilkana.app/src/lib/themes/spec.ts`, y es duplicación asumida:
     * el backend no puede leer un tipo de TypeScript. Un tipo desconocido en una plantilla
     * no se descarta como hace el normalizador del editor —ahí es defensa contra datos
     * viejos—; aquí es un tema mal hecho y tiene que doler al publicarlo.
     *
     * **La lista manda desde el frontend.** La anterior —`hero`, `grid`, `carousel`,
     * `gallery`— era de un diseño temprano; el vocabulario se afinó después en el editor de
     * portada y esto lo sigue. Quien dibuja es quien nombra: un tipo que el editor no sabe
     * pintar no debería poder guardarse en una plantilla.
     */
    public const BLOCK_TYPES = [
        // Portada y sección
        'lead-story', 'two-up', 'three-up', 'river', 'photo-essay', 'opinion-strip',
        'most-read', 'latest-in-category', 'live-ticker',
        'newsletter-card', 'ad-slot', 'agenda',
        // Cuerpo del artículo
        'paragraph', 'heading', 'image', 'quote', 'list', 'embed',
        'divider', 'paywall', 'factbox', 'related',
    ];

    /**
     * Los pares que tienen que llegar a AA (4.5:1).
     *
     * Son los mismos que ya vigila el gate de accesibilidad sobre la salida real: el texto
     * corriente, los dos grises secundarios que pintan el masthead y las metas, y el acento,
     * que es el color del kicker sobre el fondo de la hoja.
     */
    private const CONTRAST_PAIRS = [
        ['text', 'surface'],
        ['textMuted', 'surface'],
        ['textSoft', 'surface'],
        ['accent', 'surface'],
    ];

    private const AA_RATIO = 4.5;

    /**
     * @return list<string> los problemas encontrados; vacío significa que el tema puede entrar
     */
    public function validate(array $spec): array
    {
        $errores = [];

        if (! in_array($spec['masthead'] ?? null, self::MASTHEADS, true)) {
            $errores[] = 'masthead: debe ser uno de '.implode(', ', self::MASTHEADS);
        }

        $tokens = is_array($spec['tokens'] ?? null) ? $spec['tokens'] : [];
        $errores = [...$errores, ...$this->validateTokens($tokens)];
        $errores = [...$errores, ...$this->validateStyle(is_array($spec['style'] ?? null) ? $spec['style'] : [])];
        $errores = [...$errores, ...$this->validateLayouts(is_array($spec['layouts'] ?? null) ? $spec['layouts'] : [])];

        // El contraste va al final y solo si los colores son válidos: un hex mal escrito ya se
        // reportó arriba y calcular su luminancia solo añadiría ruido sobre el mismo fallo.
        if ($errores === []) {
            $errores = $this->validateContrast($tokens);
        }

        return $errores;
    }

    private function validateTokens(array $tokens): array
    {
        $errores = [];

        foreach (SnkKilkanaThemeVariantModel::COLOR_KEYS as $clave) {
            if (! isset($tokens[$clave]) || ! preg_match('/^#[0-9a-f]{6}$/i', (string) $tokens[$clave])) {
                $errores[] = "tokens.{$clave}: falta o no es un hex de seis dígitos";
            }
        }

        $errores = [...$errores, ...$this->checkUnit($tokens, 'pageRadius', 'tokens')];

        // Las familias tipográficas no se validan más allá de que existan: son una pila de
        // fuentes CSS y acotar su forma sería inventarse una gramática.
        foreach (['fontSerif', 'fontSans'] as $clave) {
            if (! is_string($tokens[$clave] ?? null) || trim((string) $tokens[$clave]) === '') {
                $errores[] = "tokens.{$clave}: falta";
            }
        }

        return $errores;
    }

    private function validateStyle(array $style): array
    {
        $errores = [];

        [$pesoMin, $pesoMax] = SnkKilkanaThemeVariantModel::WEIGHT_RANGE;
        $peso = $style['headlineWeight'] ?? null;
        if (! is_int($peso) || $peso < $pesoMin || $peso > $pesoMax || $peso % 100 !== 0) {
            $errores[] = "style.headlineWeight: centenas entre {$pesoMin} y {$pesoMax}";
        }

        [$escalaMin, $escalaMax] = SnkKilkanaThemeVariantModel::SCALE_RANGE;
        $escala = $style['headlineScale'] ?? null;
        if (! is_numeric($escala) || $escala < $escalaMin || $escala > $escalaMax) {
            $errores[] = "style.headlineScale: entre {$escalaMin} y {$escalaMax}";
        }

        foreach (['headlineTracking', 'blockPadX', 'blockPadY'] as $clave) {
            $errores = [...$errores, ...$this->checkUnit($style, $clave, 'style')];
        }

        // `kicker` y `card` son el lenguaje de diseño del paquete: mapas de valores CSS que el
        // frontend emite como custom properties. No se acotan sus valores —son CSS libre— pero
        // sí que estén y que sean cadenas, porque un nulo aquí sale a la hoja como «null».
        foreach (['kicker' => 8, 'card' => 5] as $grupo => $minimo) {
            $mapa = $style[$grupo] ?? null;
            if (! is_array($mapa) || count($mapa) < $minimo) {
                $errores[] = "style.{$grupo}: faltan claves (se esperan {$minimo})";

                continue;
            }
            foreach ($mapa as $clave => $valor) {
                if (! is_string($valor)) {
                    $errores[] = "style.{$grupo}.{$clave}: debe ser una cadena CSS";
                }
            }
        }

        return $errores;
    }

    private function validateLayouts(array $layouts): array
    {
        $errores = [];
        $conocidos = SnkKilkanaLayoutEnum::values();

        foreach ($conocidos as $documento) {
            if (! isset($layouts[$documento]) || ! is_array($layouts[$documento])) {
                $errores[] = "layouts.{$documento}: falta la plantilla de arranque";
            }
        }

        foreach ($layouts as $documento => $bandas) {
            if (! in_array($documento, $conocidos, true)) {
                $errores[] = "layouts.{$documento}: no es un documento componible";

                continue;
            }

            foreach ((array) $bandas as $i => $banda) {
                $errores = [...$errores, ...$this->validateBand($banda, "layouts.{$documento}[{$i}]")];
            }
        }

        return $errores;
    }

    /** Una banda es un bloque suelto o una fila de columnas; cada columna es un bloque. */
    private function validateBand(mixed $banda, string $donde): array
    {
        if (! is_array($banda)) {
            return ["{$donde}: no es una banda"];
        }

        if (isset($banda['cols'])) {
            if (! is_array($banda['cols']) || $banda['cols'] === []) {
                return ["{$donde}.cols: vacío"];
            }

            $errores = [];
            foreach ($banda['cols'] as $j => $columna) {
                $tipo = is_array($columna) ? ($columna['type'] ?? null) : null;
                if (! in_array($tipo, self::BLOCK_TYPES, true)) {
                    $errores[] = "{$donde}.cols[{$j}].type: «".(is_scalar($tipo) ? $tipo : '?').'» no es un bloque conocido';
                }
            }

            return $errores;
        }

        return in_array($banda['type'] ?? null, self::BLOCK_TYPES, true)
            ? []
            : ["{$donde}.type: «".(is_scalar($banda['type'] ?? null) ? $banda['type'] : '?').'» no es un bloque conocido'];
    }

    private function validateContrast(array $tokens): array
    {
        $errores = [];

        foreach (self::CONTRAST_PAIRS as [$frente, $fondo]) {
            $ratio = $this->contrast((string) $tokens[$frente], (string) $tokens[$fondo]);
            if ($ratio < self::AA_RATIO) {
                $errores[] = sprintf(
                    'contraste %s sobre %s: %.2f:1, por debajo del mínimo AA de %.1f:1 (%s / %s)',
                    $frente, $fondo, $ratio, self::AA_RATIO, $tokens[$frente], $tokens[$fondo]
                );
            }
        }

        return $errores;
    }

    /** Ratio de contraste WCAG 2.x entre dos colores. */
    private function contrast(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);
        [$claro, $oscuro] = $la >= $lb ? [$la, $lb] : [$lb, $la];

        return ($claro + 0.05) / ($oscuro + 0.05);
    }

    /** Luminancia relativa WCAG: cada canal se linealiza antes de pesarlo. */
    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $canales = [];

        foreach ([0, 2, 4] as $desplazamiento) {
            $valor = hexdec(substr($hex, $desplazamiento, 2)) / 255;
            $canales[] = $valor <= 0.03928 ? $valor / 12.92 : (($valor + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $canales[0] + 0.7152 * $canales[1] + 0.0722 * $canales[2];
    }

    /** Un número con unidad CSS dentro del rango declarado en el modelo. */
    private function checkUnit(array $fuente, string $clave, string $grupo): array
    {
        [$unidad, $min, $max] = SnkKilkanaThemeVariantModel::UNIT_RANGES[$clave];
        $valor = $fuente[$clave] ?? null;

        if (! is_string($valor) || ! preg_match('/^(-?\d+(?:\.\d+)?)'.$unidad.'$/', $valor, $partes)) {
            return ["{$grupo}.{$clave}: falta o no es un número seguido de {$unidad}"];
        }

        $numero = (float) $partes[1];

        return $numero < $min || $numero > $max
            ? ["{$grupo}.{$clave}: {$valor} fuera de {$min}{$unidad}–{$max}{$unidad}"]
            : [];
    }
}
