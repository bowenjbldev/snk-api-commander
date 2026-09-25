<?php

namespace Packages\Kilkana\Services;

use Packages\Kilkana\Enums\SnkKilkanaLayoutEnum;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;

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
 *
 * Este fichero es la autoridad del contrato de tema: vocabulario, rangos y compatibilidad de
 * catálogo se validan antes de persistir.
 */
class SnkKilkanaThemeSpecValidator
{
    /**
     * La versión del contrato que sabe leer este validador.
     *
     * **Se comprueba lo primero y no se intenta adivinar.** Un spec de la v1 leído como v2 no
     * revienta: le faltan `grid`, `type`, `media` y `density`, y el tema **se ve mal**, que es
     * mucho peor que no publicarse. Una versión desconocida es un error del catálogo, no un
     * caso a tolerar.
     */
    public const SPEC_VERSION = 2;

    /**
     * Los peldaños de la escala tipográfica: diez, y los diez obligatorios.
     *
     * **El tracking va POR PELDAÑO y no por tema**, porque un periódico no lo ajusta una
     * sola vez: El País pide −0,031em a 54 px y cero en el rótulo de banda.
     *
     * `masthead` y `nav` están en la escala para que trescientas cabeceras distintas no salgan
     * todas al mismo tamaño: la cabecera es tipografía del tema, no del componente.
     */
    public const TYPE_STEPS = [
        'hero', 'lead', 'card', 'brief', 'kicker',
        'standfirst', 'meta', 'band', 'masthead', 'nav',
    ];

    /** Los cinco recortes con nombre. Un bloque pide uno y el tema decide cuál es. */
    public const RATIO_KEYS = ['hero', 'card', 'thumb', 'portrait', 'square'];

    /**
     * Los tipos de bloque del editor: los diecisiete de portada y los diez del cuerpo.
     *
     * Ninguno inventado: salen de contar las formas de trece portadas reales medidas. Un tipo
     * desconocido en una plantilla no se descarta como hace el normalizador del editor —ahí es
     * defensa contra composiciones guardadas hace meses—; aquí es un tema mal hecho y tiene que
     * doler al publicarlo.
     */
    public const BLOCK_TYPES = [
        // Portada y sección
        'lead-story', 'lead-briefs', 'two-up', 'three-up', 'four-up', 'mosaic',
        'river', 'brief-list', 'photo-essay', 'opinion-strip',
        'most-read', 'latest-in-category', 'live-ticker',
        'newsletter-card', 'ad-slot', 'agenda',
        // Cuerpo del artículo
        'paragraph', 'heading', 'image', 'quote', 'list', 'embed',
        'divider', 'paywall', 'factbox', 'related',
    ];

    /**
     * Las piezas que dibuja cada bloque cuando está lleno.
     *
     * Es lo que acota el mínimo que un tema puede declarar: pedir cinco piezas a un bloque
     * que dibuja dos es un bloque que no sale nunca, y eso no se ve mirando el JSON —se ve
     * echando de menos una banda en la calle meses después—. El mobiliario no está: no lleva
     * piezas, y por eso se dibuja siempre.
     */
    private const BLOCK_SLOTS = [
        'lead-story' => [1, 1],
        'lead-briefs' => [4, 4],
        'two-up' => [2, 2],
        'three-up' => [3, 3],
        'four-up' => [4, 4],
        'mosaic' => [5, 5],
        'photo-essay' => [2, 2],
        'opinion-strip' => [3, 3],
        'river' => [12, 4],
        'brief-list' => [12, 6],
        'most-read' => [12, 5],
        'latest-in-category' => [12, 5],
        'live-ticker' => [12, 3],
    ];

    /**
     * Cuántas fotos dibuja cada bloque: `[tope, lo que dibuja si el tema no pide cuenta]`.
     *
     * **No es el número de huecos**, y por eso no se deduce de `count`: `lead-briefs` tiene
     * cuatro piezas y UNA sola foto —los satélites son titulares— y el río crece con su cuenta
     * hasta doce. Un bloque que no está aquí no dibuja ninguna.
     */
    private const BLOCK_IMAGES = [
        'lead-story' => [1, 1],
        'lead-briefs' => [1, 1],
        'two-up' => [2, 2],
        'three-up' => [3, 3],
        'four-up' => [4, 4],
        'mosaic' => [5, 5],
        'photo-essay' => [2, 2],
        'river' => [12, 4],
    ];

    /**
     * El presupuesto de una plantilla, y la métrica de Lighthouse que protege cada tope.
     *
     * **El spec de un tema es un dato: se cambia sin desplegar nada.** Eso lo convierte en el sitio
     * exacto por donde un periódico se vuelve lento sin que nadie lo revise, porque basta con
     * pedir cuarenta bloques y ciento veinte fotos y el JSON entra igual.
     *
     * El tope de ficheros `woff2` propios **no se comprueba aquí**: esos recursos no forman parte
     * del spec. Aquí solo llega el JSON.
     */
    public const BUDGET = [
        'bands' => 24,
        'blocks' => 48,
        'images' => 64,
        'fold' => 8,
    ];

    /**
     * Los pares que tienen que llegar a AA (4.5:1).
     *
     * **Es lo único que un tema no puede saltarse**: todo lo demás es libertad artística y esto
     * es el derecho del lector a leer. Son el texto corriente, los dos grises que pintan cabecera
     * y metas, y el acento, que es el color del antetítulo sobre el papel. `textFaint` NO está:
     * es el gris de los sellos de hora, y exigirle 4,5:1 lo dejaría indistinguible del texto
     * normal.
     */
    private const CONTRAST_PAIRS = [
        ['text', 'surface'],
        ['textMuted', 'surface'],
        ['textSoft', 'surface'],
        ['accent', 'surface'],
    ];

    private const AA_RATIO = 4.5;

    /**
     * Rangos numéricos. Anchos a propósito: los extremos son diarios reales.
     *
     * El recorrido de las trece referencias va de 20 px entre bandas (El Español) a 200 px
     * (El País), así que apretar el rango dejaría fuera a dos periódicos de verdad.
     */
    public const RANGES = [
        'sheet' => [320, 1600],
        'columns' => [1, 24],
        'gutter' => [0, 80],
        'size' => [8, 120],
        'weight' => [100, 900],
        'leading' => [0.9, 2],
        'tracking' => [-0.06, 0.3],
        'gap' => [0, 240],
        'pad' => [0, 80],
        'pageRadius' => [0, 12],
    ];

    /**
     * @return list<string> los problemas encontrados; vacío significa que el tema puede entrar
     */
    public function validate(array $spec): array
    {
        // La versión va la primera y corta: con un spec de otra versión, los cuarenta errores
        // que vendrían detrás son ruido sobre el mismo hecho.
        $version = $spec['specVersion'] ?? null;
        if ($version !== self::SPEC_VERSION) {
            return [sprintf(
                'specVersion: expected %d and got %s. A theme from another version is not interpreted, it is migrated',
                self::SPEC_VERSION,
                is_scalar($version) ? var_export($version, true) : 'nothing'
            )];
        }

        $errors = [];

        $tokens = $this->arr($spec, 'tokens');
        $errors = [
            ...$errors,
            ...$this->checkTokens($tokens),
            ...$this->checkGrid($this->arr($spec, 'grid')),
            ...$this->checkType($this->arr($spec, 'type')),
            ...$this->checkMedia($this->arr($spec, 'media')),
            ...$this->checkDensity($this->arr($spec, 'density')),
            ...$this->checkChrome($this->arr($spec, 'chrome')),
            ...$this->checkLayouts($spec),
        ];

        // El contraste va al final y solo si los colores son válidos: un hex mal escrito ya se
        // reportó arriba y calcular su luminancia solo añadiría ruido sobre el mismo fallo.
        $brokenColours = array_filter($errors, fn (string $e) => str_starts_with($e, 'tokens.'));
        if ($brokenColours === []) {
            $errors = [...$errors, ...$this->checkContrast($tokens)];
        }

        return array_values($errors);
    }

    private function checkTokens(array $tokens): array
    {
        $errors = [];

        foreach (SnkKilkanaSettingModel::COLOR_KEYS as $key) {
            if (! $this->isHex($tokens[$key] ?? null)) {
                $errors[] = "tokens.{$key}: missing or not a six-digit hex";
            }
        }

        $errors = [...$errors, ...$this->checkPx($tokens['pageRadius'] ?? null, 'tokens.pageRadius', 'pageRadius')];

        // El marco es opcional —lo tiene El Chapuzas, que compone sobre un fondo oscuro— y si
        // viene tiene que ser un color de verdad, no una clave del tema: pinta fuera de la hoja.
        if (array_key_exists('frame', $tokens) && ! $this->isHex($tokens['frame'])) {
            $errors[] = 'tokens.frame: not a six-digit hex';
        }

        return $errors;
    }

    /**
     * La rejilla de la hoja, que es sobre la que se reparten los `span` de cada fila.
     *
     * `columns` para las iguales —lo más común— y `tracks` para las desiguales, que existen y
     * no son raras: AS reparte 272/272/172/302. Son EXCLUYENTES porque con las dos no se sabe
     * cuál manda al repartir los `span`.
     */
    private function checkGrid(array $grid): array
    {
        if ($grid === []) {
            return ['grid: missing'];
        }

        $errors = [];

        if (! $this->inRange($grid['sheet'] ?? null, 'sheet')) {
            $errors[] = 'grid.sheet: between 320 and 1600 px';
        }

        $hasColumns = is_int($grid['columns'] ?? null);
        $hasTracks = is_array($grid['tracks'] ?? null);

        if ($hasColumns === $hasTracks) {
            $errors[] = 'grid: declare `columns` OR `tracks`, exactly one';
        }
        if ($hasColumns && ! $this->inRange($grid['columns'], 'columns')) {
            $errors[] = 'grid.columns: between 1 and 24';
        }
        if ($hasTracks) {
            if ($grid['tracks'] === []) {
                $errors[] = 'grid.tracks: cannot be empty';
            }
            foreach ($grid['tracks'] as $width) {
                if (! $this->inRange($width, 'sheet')) {
                    $errors[] = 'grid.tracks: widths in px';

                    break;
                }
            }
        }

        $n = $this->gridSize($grid);
        $gutters = $grid['gutter'] ?? null;

        if (is_array($gutters)) {
            // Un medianil por calle: ¡HOLA! usa 32 px dentro de la hoja estrecha y 48 en la ancha.
            $expected = max(0, $n - 1);
            if (count($gutters) !== $expected) {
                $errors[] = "grid.gutter: {$expected} gutters for {$n} columns";
            }
            foreach ($gutters as $width) {
                if (! $this->inRange($width, 'gutter')) {
                    $errors[] = 'grid.gutter: between 0 and 80 px';

                    break;
                }
            }
        } elseif (! $this->inRange($gutters, 'gutter')) {
            $errors[] = 'grid.gutter: between 0 and 80 px';
        }

        // El filete vertical del medianil: tres de trece lo tienen y es media identidad
        // «periódico». Nulo es no tenerlo, que es lo normal.
        $rule = $grid['rule'] ?? null;
        if (is_array($rule)) {
            if (! $this->inRange($rule['width'] ?? null, 'gutter')) {
                $errors[] = 'grid.rule.width: in px';
            }
            if (! $this->isColor($rule['color'] ?? null)) {
                $errors[] = 'grid.rule.color: hex or theme key';
            }
            if (! in_array($rule['style'] ?? null, ['solid', 'dashed', 'dotted'], true)) {
                $errors[] = 'grid.rule.style: solid, dashed or dotted';
            }
        }

        return $errors;
    }

    private function checkType(array $type): array
    {
        if ($type === []) {
            return ['type: missing'];
        }

        $errors = [];

        foreach (['display', 'text'] as $key) {
            if (! is_string($type[$key] ?? null) || trim($type[$key]) === '') {
                $errors[] = "type.{$key}: missing font family";
            }
        }

        // La familia del nombre del periódico es opcional: sin ella, el nombre va en la del titular.
        if (array_key_exists('masthead', $type) && (! is_string($type['masthead']) || trim($type['masthead']) === '')) {
            $errors[] = 'type.masthead: empty font family';
        }

        $steps = is_array($type['steps'] ?? null) ? $type['steps'] : [];

        foreach (self::TYPE_STEPS as $name) {
            $step = $steps[$name] ?? null;
            if (! is_array($step)) {
                $errors[] = "type.steps.{$name}: missing";

                continue;
            }

            $where = "type.steps.{$name}";

            if (! $this->inRange($step['size'] ?? null, 'size')) {
                $errors[] = "{$where}.size: between 8 and 120 px";
            }

            $weight = $step['weight'] ?? null;
            if (! $this->inRange($weight, 'weight') || $weight % 100 !== 0) {
                $errors[] = "{$where}.weight: hundreds between 100 and 900";
            }

            if (! $this->inRange($step['leading'] ?? null, 'leading')) {
                $errors[] = "{$where}.leading: between 0.9 and 2";
            }

            $errors = [...$errors, ...$this->checkEm($step['tracking'] ?? null, "{$where}.tracking")];

            if (array_key_exists('color', $step) && ! $this->isColor($step['color'])) {
                $errors[] = "{$where}.color: hex or theme key";
            }
        }

        // Sin esto, un tema puede declarar la apertura más pequeña que la tarjeta y no se ve
        // hasta abrir la portada. El Chapuzas las tiene IGUALES a propósito —eso vale—, pero
        // una apertura menor que su tarjeta es siempre un error de tecleo.
        $lead = $steps['hero']['size'] ?? null;
        $card = $steps['card']['size'] ?? null;
        if (is_numeric($lead) && is_numeric($card) && $lead < $card) {
            $errors[] = "type.steps.hero: {$lead}px is smaller than the card ({$card}px)";
        }

        return $errors;
    }

    /**
     * Los cinco recortes, en forma `ancho/alto`.
     *
     * El apaisado no se da por hecho: El Confidencial usa cuadrado, AS vídeo vertical y en
     * ¡HOLA! la relación la manda la sección. Son obligatorios porque son lo que declara la
     * altura de cada foto antes de cargarla, y de ahí sale el CLS.
     */
    private function checkMedia(array $media): array
    {
        $errors = [];

        foreach (self::RATIO_KEYS as $key) {
            $value = $media[$key] ?? null;
            if (! is_string($value) || ! preg_match('/^\d+(\.\d+)?\/\d+(\.\d+)?$/', $value)) {
                $errors[] = "media.{$key}: missing or not in «width/height» form";
            }
        }

        return $errors;
    }

    /** El aire, en px y como número: la unidad la pone quien emite el CSS, no el tema. */
    private function checkDensity(array $density): array
    {
        if ($density === []) {
            return ['density: missing'];
        }

        $errors = [];

        foreach (['rowGap', 'blockGap'] as $key) {
            if (! $this->inRange($density[$key] ?? null, 'gap')) {
                $errors[] = "density.{$key}: between 0 and 240 px";
            }
        }
        foreach (['padX', 'padY'] as $key) {
            if (! $this->inRange($density[$key] ?? null, 'pad')) {
                $errors[] = "density.{$key}: between 0 and 80 px";
            }
        }

        return $errors;
    }

    /**
     * CSS libre para el antetítulo y la tarjeta.
     *
     * No se acotan los valores —son CSS— pero sí que estén y que sean cadenas, porque un nulo
     * aquí sale a la hoja como la cadena «null» y rompe el color de algo.
     */
    private function checkChrome(array $chrome): array
    {
        $errors = [];

        foreach (['kicker' => 3, 'card' => 5] as $group => $minimum) {
            $map = $chrome[$group] ?? null;
            if (! is_array($map) || count($map) < $minimum) {
                $errors[] = "chrome.{$group}: missing keys (expected {$minimum})";

                continue;
            }
            foreach ($map as $key => $value) {
                if (! is_string($value)) {
                    $errors[] = "chrome.{$group}.{$key}: must be a CSS string";
                }
            }
        }

        return $errors;
    }

    /**
     * Las plantillas de arranque: **fila → columnas → bloques**.
     *
     * Las tres alturas son las que hacen escribible un raíl: el pozo de nueve columnas de El
     * País lleva su apertura mientras el raíl de tres corre en paralelo con su destacado, sus
     * dos tarjetas y su anuncio. Una banda a todo el ancho es una fila con UNA columna, y no
     * hay dos formas de decir lo mismo.
     */
    private function checkLayouts(array $spec): array
    {
        $errors = [];
        // Los que una redacción compone, y por tanto los que la base guarda.
        $required = SnkKilkanaLayoutEnum::values();
        // Más el de arranque, que es del TEMA y no se guarda: sirve para que un periódico
        // recién montado no reciba una composición con más huecos vacíos que artículos, y
        // en cuanto se aplica deja de existir. Por eso no está en el enum.
        $known = [...$required, 'starter'];
        $total = $this->gridSize($this->arr($spec, 'grid'));
        $layouts = $this->arr($spec, 'layouts');

        foreach ($required as $layout) {
            if (! is_array($layouts[$layout] ?? null)) {
                $errors[] = "layouts.{$layout}: missing the starter template";
            }
        }

        foreach ($layouts as $layout => $rows) {
            if (! in_array($layout, $known, true)) {
                $errors[] = "layouts.{$layout}: not a composable document";

                continue;
            }

            // El presupuesto se cuenta mientras se recorre, que es el único recorrido que hay:
            // contarlo aparte sería recorrer dos veces lo mismo para decir lo mismo.
            $blocks = 0;
            $photos = 0;
            $aboveFold = 0;

            foreach ((array) $rows as $i => $row) {
                $where = "layouts.{$layout}[{$i}]";
                $columns = is_array($row) && is_array($row['cols'] ?? null) ? $row['cols'] : [];

                if ($columns === []) {
                    $errors[] = "{$where}: a row needs at least one column";

                    continue;
                }

                // La suma tiene que cuadrar: una fila que no llena la rejilla deja un hueco que
                // nadie pidió, y una que se pasa desborda la hoja sin aviso.
                $sum = array_sum(array_map(fn ($c) => is_array($c) ? ($c['span'] ?? 0) : 0, $columns));
                if ($total > 0 && $sum !== $total) {
                    $errors[] = "{$where}: the spans add up to {$sum} and the grid has {$total}";
                }

                foreach ($columns as $column) {
                    $columnBlocks = is_array($column) && is_array($column['blocks'] ?? null) ? $column['blocks'] : [];
                    if ($columnBlocks === []) {
                        $errors[] = "{$where}: a column needs at least one block";

                        continue;
                    }

                    foreach ($columnBlocks as $block) {
                        $blockType = is_array($block) ? ($block['type'] ?? null) : null;
                        if (! in_array($blockType, self::BLOCK_TYPES, true)) {
                            $errors[] = "{$where}: «".(is_scalar($blockType) ? $blockType : '?').'» is not an editor block';

                            continue;
                        }

                        $blocks++;
                        $n = $this->imagesIn($block);
                        $photos += $n;
                        // Las dos primeras bandas son lo que se ve sin desplazar, y ahí está el
                        // LCP: lo que cargue por encima del pliegue compite con él.
                        if ($i < 2) {
                            $aboveFold += $n;
                        }

                        $errors = [
                            ...$errors,
                            ...$this->checkSection(
                                $block['config']['data']['section'] ?? null,
                                "{$where}.blocks.data.section"
                            ),
                            ...$this->checkMin($block, $where),
                        ];
                    }
                }

                $errors = [...$errors, ...$this->checkSection($row['section'] ?? null, "{$where}.section")];

                $header = is_array($row['header'] ?? null) ? $row['header'] : null;
                if ($header !== null) {
                    // Un rótulo derivado necesita de dónde derivarlo. En el documento de
                    // sección la fuente es la que se estrena; en la portada la declara la fila,
                    // o el rótulo sale vacío y la banda pierde la mitad de lo que la separa de
                    // la de al lado.
                    if (($header['from'] ?? null) === 'section') {
                        if ($layout !== 'section' && ! isset($row['section'])) {
                            $errors[] = "{$where}.header: derives from the section and the row declares none";
                        }
                    } elseif (trim((string) ($header['text'] ?? '')) === '') {
                        $errors[] = "{$where}.header: the label cannot be empty";
                    }
                }

                foreach ((array) ($row['skin'] ?? []) as $key => $value) {
                    if ($key !== 'bleed' && ! $this->isColor($value)) {
                        $errors[] = "{$where}.skin.{$key}: hex or theme key";
                    }
                }
            }

            $errors = [
                ...$errors,
                ...$this->overBudget($layout, 'bands', count((array) $rows), 'bands', 'DOM size'),
                ...$this->overBudget($layout, 'blocks', $blocks, 'blocks', 'DOM size and TBT'),
                ...$this->overBudget($layout, 'photos', $photos, 'images', 'page weight'),
                ...$this->overBudget($layout, 'photos above the fold', $aboveFold, 'fold', 'LCP'),
            ];
        }

        return $errors;
    }

    private function overBudget(string $layout, string $what, int $count, string $cap, string $metric): array
    {
        return $count > self::BUDGET[$cap]
            ? ["layouts.{$layout}: {$what} {$count}, the budget is ".self::BUDGET[$cap]." ({$metric})"]
            : [];
    }

    /**
     * La sección que alimenta una fila o un bloque, **por posición y no por nombre**.
     *
     * Un tema se replica en la base de cada periódico, así que no puede conocer sus
     * secciones: pedir una por su nombre es pedir la de la redacción para la que se
     * compuso, y en cualquier otra ese bloque se queda sin nada. `nth` pide la enésima
     * sección viva, en el orden del menú del propio periódico, y un menú no llega a
     * veinticuatro entradas.
     */
    private function checkSection(mixed $ref, string $where): array
    {
        if ($ref === null) {
            return [];
        }

        $nth = is_array($ref) ? ($ref['nth'] ?? null) : null;

        return is_int($nth) && $this->inRange($nth, 'columns')
            ? []
            : ["{$where}.nth: integer between 1 and 24, one-indexed"];
    }

    /**
     * El mínimo de piezas por debajo del cual un bloque no se dibuja en la calle.
     *
     * Un bloque sabe cuántas necesita para parecerse a lo que es: un río con dos entradas es
     * un río corto, y un mosaico con dos no es un mosaico. Esa diferencia no se deduce
     * contando columnas —es un juicio de quien diseñó la banda—, así que la declara el tema y
     * por omisión es una, que es lo que hace que el primer artículo de un periódico salga en
     * portada.
     */
    private function checkMin(array $block, string $where): array
    {
        $min = $block['config']['min'] ?? null;
        if ($min === null) {
            return [];
        }

        $range = self::BLOCK_SLOTS[$block['type']] ?? null;
        $posts = 0;
        if ($range !== null) {
            [$cap, $base] = $range;
            $requested = $block['config']['data']['count'] ?? null;
            $posts = is_int($requested) && $requested > 0 ? min($requested, $cap) : $base;
        }

        return is_int($min) && $min >= 1 && $min <= $posts
            ? []
            : ["{$where}: «{$block['type']}» asks for a minimum of {$min} and draws {$posts} posts"];
    }

    /** Cuántas fotos dibuja un bloque, con lo que el tema haya pedido encima. */
    private function imagesIn(array $block): int
    {
        $range = self::BLOCK_IMAGES[$block['type']] ?? null;
        if ($range === null) {
            return 0;
        }

        $config = is_array($block['config'] ?? null) ? $block['config'] : [];
        if (($config['photo'] ?? null) === false) {
            return 0;
        }

        [$cap, $base] = $range;
        $requested = is_array($config['data'] ?? null) ? ($config['data']['count'] ?? null) : null;

        return is_int($requested) && $requested > 0 ? min($requested, $cap) : $base;
    }

    private function checkContrast(array $tokens): array
    {
        $errors = [];

        foreach (self::CONTRAST_PAIRS as [$foreground, $background]) {
            $ratio = $this->contrast((string) $tokens[$foreground], (string) $tokens[$background]);
            if ($ratio < self::AA_RATIO) {
                $errors[] = sprintf(
                    'contrast %s on %s: %.2f:1, below the AA minimum of %.1f:1 (%s / %s)',
                    $foreground, $background, $ratio, self::AA_RATIO, $tokens[$foreground], $tokens[$background]
                );
            }
        }

        return $errors;
    }

    /** Ratio de contraste WCAG 2.x entre dos colores. */
    private function contrast(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);
        [$lighter, $darker] = $la >= $lb ? [$la, $lb] : [$lb, $la];

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /** Luminancia relativa WCAG: cada canal se linealiza antes de pesarlo. */
    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = [];

        foreach ([0, 2, 4] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** Cuántas columnas tiene esta rejilla, venga por `columns` o por `tracks`. */
    private function gridSize(array $grid): int
    {
        if (is_array($grid['tracks'] ?? null)) {
            return count($grid['tracks']);
        }

        return is_int($grid['columns'] ?? null) ? $grid['columns'] : 0;
    }

    private function arr(array $source, string $key): array
    {
        return is_array($source[$key] ?? null) ? $source[$key] : [];
    }

    private function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) === 1;
    }

    /** Un color del tema puede escribirse en hex o nombrando una clave de `tokens`. */
    private function isColor(mixed $value): bool
    {
        return $this->isHex($value)
            || (is_string($value) && in_array($value, SnkKilkanaSettingModel::COLOR_KEYS, true));
    }

    private function inRange(mixed $value, string $range): bool
    {
        if (! is_int($value) && ! is_float($value)) {
            return false;
        }

        [$min, $max] = self::RANGES[$range];

        return $value >= $min && $value <= $max;
    }

    /** Un número seguido de `px`, dentro del rango declarado. */
    private function checkPx(mixed $value, string $where, string $range): array
    {
        if (! is_string($value) || ! preg_match('/^(-?\d+(?:\.\d+)?)px$/', $value, $parts)) {
            return ["{$where}: missing or not a number followed by px"];
        }

        [$min, $max] = self::RANGES[$range];
        $number = (float) $parts[1];

        return $number < $min || $number > $max
            ? ["{$where}: {$value} outside {$min}px–{$max}px"]
            : [];
    }

    /** Un número seguido de `em`, dentro del rango de tracking. */
    private function checkEm(mixed $value, string $where): array
    {
        if (! is_string($value) || ! preg_match('/^(-?\d+(?:\.\d+)?)em$/', $value, $parts)) {
            return ["{$where}: must be in em"];
        }

        [$min, $max] = self::RANGES['tracking'];
        $number = (float) $parts[1];

        return $number < $min || $number > $max
            ? ["{$where}: between {$min} and {$max} em"]
            : [];
    }
}
