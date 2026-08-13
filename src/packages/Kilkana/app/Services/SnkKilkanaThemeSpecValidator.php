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
 *
 * **Es el espejo en PHP de `kilkana.app/src/lib/themes/spec.ts`.** La duplicación es asumida
 * —PHP no puede leer un tipo de TypeScript— pero divergir no lo es: un tema que entra aquí y no
 * allí, o al revés, es un catálogo roto. Quien dibuja es quien nombra, así que el vocabulario y
 * los rangos vienen del frontend y este fichero los sigue.
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

    /** Las cabeceras que sabe pintar el frontend. Es un componente, no un dato libre. */
    public const MASTHEADS = ['compact', 'daily'];

    /**
     * Los peldaños de la escala tipográfica: diez, y los diez obligatorios.
     *
     * **El tracking va POR PELDAÑO y no por variante**, porque un periódico no lo ajusta una
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
        'most-read', 'latest-in-category', 'live-ticker', 'topic-strip',
        'newsletter-card', 'ad-slot', 'agenda',
        // Cuerpo del artículo
        'paragraph', 'heading', 'image', 'quote', 'list', 'embed',
        'divider', 'paywall', 'factbox', 'related',
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
     * **Un tema es un dato: se publica sin desplegar nada.** Eso lo convierte en el sitio
     * exacto por donde un periódico se vuelve lento sin que nadie lo revise, porque basta con
     * pedir cuarenta bloques y ciento veinte fotos y el JSON entra igual.
     *
     * El tope de ficheros `woff2` propios **no se comprueba aquí**: las fuentes viajan en la
     * carpeta del tema, dentro del repositorio del frontend, y quien las cuenta es su catálogo
     * al construirse. Aquí solo llega el JSON.
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
                'specVersion: se esperaba %d y llegó %s. Un tema de otra versión no se interpreta, se migra',
                self::SPEC_VERSION,
                is_scalar($version) ? var_export($version, true) : 'nada'
            )];
        }

        $errores = [];

        if (! in_array($spec['masthead'] ?? null, self::MASTHEADS, true)) {
            $errores[] = 'masthead: debe ser uno de '.implode(', ', self::MASTHEADS);
        }

        $tokens = $this->arr($spec, 'tokens');
        $errores = [
            ...$errores,
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
        $colorRoto = array_filter($errores, fn (string $e) => str_starts_with($e, 'tokens.'));
        if ($colorRoto === []) {
            $errores = [...$errores, ...$this->checkContrast($tokens)];
        }

        return array_values($errores);
    }

    /**
     * Lo que define la familia, y que **todas las variantes de un paquete comparten**.
     *
     * El `spec` es autosuficiente —copiarlo lo dibuja— y el precio de esa autonomía es que la
     * rejilla y las tipografías se repiten en cada variante. Repetido y sin comprobar sería una
     * promesa rota: una variante con otro ancho de hoja ya no es la misma cabecera. Si dos
     * variantes no se reconocen como el mismo periódico, son dos temas.
     *
     * @param  list<array>  $variantes  las variantes del fichero maestro, con su `spec`
     * @return list<string> los desacuerdos; vacío significa que son la misma familia
     */
    public function validateFamily(array $variantes): array
    {
        if (count($variantes) < 2) {
            return [];
        }

        $rasgos = function (array $variante): array {
            $spec = is_array($variante['spec'] ?? null) ? $variante['spec'] : [];
            $grid = is_array($spec['grid'] ?? null) ? $spec['grid'] : [];
            $type = is_array($spec['type'] ?? null) ? $spec['type'] : [];

            return [
                'masthead' => $spec['masthead'] ?? null,
                'sheet' => $grid['sheet'] ?? null,
                'columns' => $this->gridSize($grid),
                'display' => $type['display'] ?? null,
                'text' => $type['text'] ?? null,
            ];
        };

        $primera = array_shift($variantes);
        $base = $rasgos($primera);
        $errores = [];

        foreach ($variantes as $variante) {
            foreach ($rasgos($variante) as $clave => $valor) {
                if ($valor !== $base[$clave]) {
                    $errores[] = sprintf(
                        '«%s» rompe la familia en %s: %s contra %s en «%s»',
                        $variante['slug'] ?? '?',
                        $clave,
                        json_encode($valor),
                        json_encode($base[$clave]),
                        $primera['slug'] ?? '?'
                    );
                }
            }
        }

        return $errores;
    }

    private function checkTokens(array $tokens): array
    {
        $errores = [];

        foreach (SnkKilkanaThemeVariantModel::COLOR_KEYS as $clave) {
            if (! $this->isHex($tokens[$clave] ?? null)) {
                $errores[] = "tokens.{$clave}: falta o no es un hex de seis dígitos";
            }
        }

        $errores = [...$errores, ...$this->checkPx($tokens['pageRadius'] ?? null, 'tokens.pageRadius', 'pageRadius')];

        // El marco es opcional —lo tiene El Chapuzas, que compone sobre un fondo oscuro— y si
        // viene tiene que ser un color de verdad, no una clave del tema: pinta fuera de la hoja.
        if (array_key_exists('frame', $tokens) && ! $this->isHex($tokens['frame'])) {
            $errores[] = 'tokens.frame: no es un hex de seis dígitos';
        }

        return $errores;
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
            return ['grid: falta'];
        }

        $errores = [];

        if (! $this->inRange($grid['sheet'] ?? null, 'sheet')) {
            $errores[] = 'grid.sheet: entre 320 y 1600 px';
        }

        $tieneColumnas = is_int($grid['columns'] ?? null);
        $tieneTracks = is_array($grid['tracks'] ?? null);

        if ($tieneColumnas === $tieneTracks) {
            $errores[] = 'grid: declara `columns` O `tracks`, exactamente uno';
        }
        if ($tieneColumnas && ! $this->inRange($grid['columns'], 'columns')) {
            $errores[] = 'grid.columns: entre 1 y 24';
        }
        if ($tieneTracks) {
            if ($grid['tracks'] === []) {
                $errores[] = 'grid.tracks: no puede ir vacío';
            }
            foreach ($grid['tracks'] as $ancho) {
                if (! $this->inRange($ancho, 'sheet')) {
                    $errores[] = 'grid.tracks: anchos en px';

                    break;
                }
            }
        }

        $n = $this->gridSize($grid);
        $calle = $grid['gutter'] ?? null;

        if (is_array($calle)) {
            // Un medianil por calle: ¡HOLA! usa 32 px dentro de la hoja estrecha y 48 en la ancha.
            $esperadas = max(0, $n - 1);
            if (count($calle) !== $esperadas) {
                $errores[] = "grid.gutter: {$esperadas} calles para {$n} columnas";
            }
            foreach ($calle as $ancho) {
                if (! $this->inRange($ancho, 'gutter')) {
                    $errores[] = 'grid.gutter: entre 0 y 80 px';

                    break;
                }
            }
        } elseif (! $this->inRange($calle, 'gutter')) {
            $errores[] = 'grid.gutter: entre 0 y 80 px';
        }

        // El filete vertical del medianil: tres de trece lo tienen y es media identidad
        // «periódico». Nulo es no tenerlo, que es lo normal.
        $filete = $grid['rule'] ?? null;
        if (is_array($filete)) {
            if (! $this->inRange($filete['width'] ?? null, 'gutter')) {
                $errores[] = 'grid.rule.width: en px';
            }
            if (! $this->isColor($filete['color'] ?? null)) {
                $errores[] = 'grid.rule.color: hex o clave del tema';
            }
            if (! in_array($filete['style'] ?? null, ['solid', 'dashed', 'dotted'], true)) {
                $errores[] = 'grid.rule.style: solid, dashed o dotted';
            }
        }

        return $errores;
    }

    private function checkType(array $type): array
    {
        if ($type === []) {
            return ['type: falta'];
        }

        $errores = [];

        foreach (['display', 'text'] as $clave) {
            if (! is_string($type[$clave] ?? null) || trim($type[$clave]) === '') {
                $errores[] = "type.{$clave}: falta la familia";
            }
        }

        $peldanos = is_array($type['steps'] ?? null) ? $type['steps'] : [];

        foreach (self::TYPE_STEPS as $nombre) {
            $peldano = $peldanos[$nombre] ?? null;
            if (! is_array($peldano)) {
                $errores[] = "type.steps.{$nombre}: falta";

                continue;
            }

            $donde = "type.steps.{$nombre}";

            if (! $this->inRange($peldano['size'] ?? null, 'size')) {
                $errores[] = "{$donde}.size: entre 8 y 120 px";
            }

            $peso = $peldano['weight'] ?? null;
            if (! $this->inRange($peso, 'weight') || $peso % 100 !== 0) {
                $errores[] = "{$donde}.weight: centenas entre 100 y 900";
            }

            if (! $this->inRange($peldano['leading'] ?? null, 'leading')) {
                $errores[] = "{$donde}.leading: entre 0.9 y 2";
            }

            $errores = [...$errores, ...$this->checkEm($peldano['tracking'] ?? null, "{$donde}.tracking")];

            if (array_key_exists('color', $peldano) && ! $this->isColor($peldano['color'])) {
                $errores[] = "{$donde}.color: hex o clave del tema";
            }
        }

        // Sin esto, un tema puede declarar la apertura más pequeña que la tarjeta y no se ve
        // hasta abrir la portada. El Chapuzas las tiene IGUALES a propósito —eso vale—, pero
        // una apertura menor que su tarjeta es siempre un error de tecleo.
        $apertura = $peldanos['hero']['size'] ?? null;
        $tarjeta = $peldanos['card']['size'] ?? null;
        if (is_numeric($apertura) && is_numeric($tarjeta) && $apertura < $tarjeta) {
            $errores[] = "type.steps.hero: {$apertura}px es menor que la tarjeta ({$tarjeta}px)";
        }

        return $errores;
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
        $errores = [];

        foreach (self::RATIO_KEYS as $clave) {
            $valor = $media[$clave] ?? null;
            if (! is_string($valor) || ! preg_match('/^\d+(\.\d+)?\/\d+(\.\d+)?$/', $valor)) {
                $errores[] = "media.{$clave}: falta o no tiene forma «ancho/alto»";
            }
        }

        return $errores;
    }

    /** El aire, en px y como número: la unidad la pone quien emite el CSS, no el tema. */
    private function checkDensity(array $density): array
    {
        if ($density === []) {
            return ['density: falta'];
        }

        $errores = [];

        foreach (['rowGap', 'blockGap'] as $clave) {
            if (! $this->inRange($density[$clave] ?? null, 'gap')) {
                $errores[] = "density.{$clave}: entre 0 y 240 px";
            }
        }
        foreach (['padX', 'padY'] as $clave) {
            if (! $this->inRange($density[$clave] ?? null, 'pad')) {
                $errores[] = "density.{$clave}: entre 0 y 80 px";
            }
        }

        return $errores;
    }

    /**
     * CSS libre para el antetítulo y la tarjeta.
     *
     * No se acotan los valores —son CSS— pero sí que estén y que sean cadenas, porque un nulo
     * aquí sale a la hoja como la cadena «null» y rompe el color de algo.
     */
    private function checkChrome(array $chrome): array
    {
        $errores = [];

        foreach (['kicker' => 3, 'card' => 5] as $grupo => $minimo) {
            $mapa = $chrome[$grupo] ?? null;
            if (! is_array($mapa) || count($mapa) < $minimo) {
                $errores[] = "chrome.{$grupo}: faltan claves (se esperan {$minimo})";

                continue;
            }
            foreach ($mapa as $clave => $valor) {
                if (! is_string($valor)) {
                    $errores[] = "chrome.{$grupo}.{$clave}: debe ser una cadena CSS";
                }
            }
        }

        return $errores;
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
        $errores = [];
        $conocidos = SnkKilkanaLayoutEnum::values();
        $total = $this->gridSize($this->arr($spec, 'grid'));
        $layouts = $this->arr($spec, 'layouts');

        foreach ($conocidos as $documento) {
            if (! is_array($layouts[$documento] ?? null)) {
                $errores[] = "layouts.{$documento}: falta la plantilla de arranque";
            }
        }

        foreach ($layouts as $documento => $filas) {
            if (! in_array($documento, $conocidos, true)) {
                $errores[] = "layouts.{$documento}: no es un documento componible";

                continue;
            }

            // El presupuesto se cuenta mientras se recorre, que es el único recorrido que hay:
            // contarlo aparte sería recorrer dos veces lo mismo para decir lo mismo.
            $bloques = 0;
            $fotos = 0;
            $pliegue = 0;

            foreach ((array) $filas as $i => $fila) {
                $donde = "layouts.{$documento}[{$i}]";
                $columnas = is_array($fila) && is_array($fila['cols'] ?? null) ? $fila['cols'] : [];

                if ($columnas === []) {
                    $errores[] = "{$donde}: una fila necesita al menos una columna";

                    continue;
                }

                // La suma tiene que cuadrar: una fila que no llena la rejilla deja un hueco que
                // nadie pidió, y una que se pasa desborda la hoja sin aviso.
                $suma = array_sum(array_map(fn ($c) => is_array($c) ? ($c['span'] ?? 0) : 0, $columnas));
                if ($total > 0 && $suma !== $total) {
                    $errores[] = "{$donde}: los span suman {$suma} y la rejilla tiene {$total}";
                }

                foreach ($columnas as $columna) {
                    $lista = is_array($columna) && is_array($columna['blocks'] ?? null) ? $columna['blocks'] : [];
                    if ($lista === []) {
                        $errores[] = "{$donde}: una columna necesita al menos un bloque";

                        continue;
                    }

                    foreach ($lista as $bloque) {
                        $tipo = is_array($bloque) ? ($bloque['type'] ?? null) : null;
                        if (! in_array($tipo, self::BLOCK_TYPES, true)) {
                            $errores[] = "{$donde}: «".(is_scalar($tipo) ? $tipo : '?').'» no es un bloque del editor';

                            continue;
                        }

                        $bloques++;
                        $n = $this->imagesIn($bloque);
                        $fotos += $n;
                        // Las dos primeras bandas son lo que se ve sin desplazar, y ahí está el
                        // LCP: lo que cargue por encima del pliegue compite con él.
                        if ($i < 2) {
                            $pliegue += $n;
                        }

                        $errores = [
                            ...$errores,
                            ...$this->checkSection(
                                $bloque['config']['data']['section'] ?? null,
                                "{$donde}.blocks.data.section"
                            ),
                        ];
                    }
                }

                $errores = [...$errores, ...$this->checkSection($fila['section'] ?? null, "{$donde}.section")];

                $rotulo = is_array($fila['header'] ?? null) ? $fila['header'] : null;
                if ($rotulo !== null) {
                    // Un rótulo derivado necesita de dónde derivarlo. En el documento de
                    // sección la fuente es la que se estrena; en la portada la declara la fila,
                    // o el rótulo sale vacío y la banda pierde la mitad de lo que la separa de
                    // la de al lado.
                    if (($rotulo['from'] ?? null) === 'section') {
                        if ($documento !== 'section' && ! isset($fila['section'])) {
                            $errores[] = "{$donde}.header: deriva de la sección y la fila no declara ninguna";
                        }
                    } elseif (trim((string) ($rotulo['text'] ?? '')) === '') {
                        $errores[] = "{$donde}.header: el rótulo no puede ir vacío";
                    }
                }

                foreach ((array) ($fila['skin'] ?? []) as $clave => $valor) {
                    if ($clave !== 'bleed' && ! $this->isColor($valor)) {
                        $errores[] = "{$donde}.skin.{$clave}: hex o clave del tema";
                    }
                }
            }

            $errores = [
                ...$errores,
                ...$this->overBudget($documento, 'bandas', count((array) $filas), 'bands', 'DOM size'),
                ...$this->overBudget($documento, 'bloques', $bloques, 'blocks', 'DOM size y TBT'),
                ...$this->overBudget($documento, 'fotos', $fotos, 'images', 'peso de la página'),
                ...$this->overBudget($documento, 'fotos por encima del pliegue', $pliegue, 'fold', 'LCP'),
            ];
        }

        return $errores;
    }

    private function overBudget(string $documento, string $que, int $tiene, string $tope, string $metrica): array
    {
        return $tiene > self::BUDGET[$tope]
            ? ["layouts.{$documento}: {$que} {$tiene}, el presupuesto es ".self::BUDGET[$tope]." ({$metrica})"]
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
    private function checkSection(mixed $ref, string $donde): array
    {
        if ($ref === null) {
            return [];
        }

        $nth = is_array($ref) ? ($ref['nth'] ?? null) : null;

        return is_int($nth) && $this->inRange($nth, 'columns')
            ? []
            : ["{$donde}.nth: entero entre 1 y 24, uno-indexado"];
    }

    /** Cuántas fotos dibuja un bloque, con lo que el tema haya pedido encima. */
    private function imagesIn(array $bloque): int
    {
        $rango = self::BLOCK_IMAGES[$bloque['type']] ?? null;
        if ($rango === null) {
            return 0;
        }

        $config = is_array($bloque['config'] ?? null) ? $bloque['config'] : [];
        if (($config['photo'] ?? null) === false) {
            return 0;
        }

        [$tope, $base] = $rango;
        $pedidas = is_array($config['data'] ?? null) ? ($config['data']['count'] ?? null) : null;

        return is_int($pedidas) && $pedidas > 0 ? min($pedidas, $tope) : $base;
    }

    private function checkContrast(array $tokens): array
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

    /** Cuántas columnas tiene esta rejilla, venga por `columns` o por `tracks`. */
    private function gridSize(array $grid): int
    {
        if (is_array($grid['tracks'] ?? null)) {
            return count($grid['tracks']);
        }

        return is_int($grid['columns'] ?? null) ? $grid['columns'] : 0;
    }

    private function arr(array $fuente, string $clave): array
    {
        return is_array($fuente[$clave] ?? null) ? $fuente[$clave] : [];
    }

    private function isHex(mixed $valor): bool
    {
        return is_string($valor) && preg_match('/^#[0-9a-f]{6}$/i', $valor) === 1;
    }

    /** Un color del tema puede escribirse en hex o nombrando una clave de `tokens`. */
    private function isColor(mixed $valor): bool
    {
        return $this->isHex($valor)
            || (is_string($valor) && in_array($valor, SnkKilkanaThemeVariantModel::COLOR_KEYS, true));
    }

    private function inRange(mixed $valor, string $rango): bool
    {
        if (! is_int($valor) && ! is_float($valor)) {
            return false;
        }

        [$min, $max] = self::RANGES[$rango];

        return $valor >= $min && $valor <= $max;
    }

    /** Un número seguido de `px`, dentro del rango declarado. */
    private function checkPx(mixed $valor, string $donde, string $rango): array
    {
        if (! is_string($valor) || ! preg_match('/^(-?\d+(?:\.\d+)?)px$/', $valor, $partes)) {
            return ["{$donde}: falta o no es un número seguido de px"];
        }

        [$min, $max] = self::RANGES[$rango];
        $numero = (float) $partes[1];

        return $numero < $min || $numero > $max
            ? ["{$donde}: {$valor} fuera de {$min}px–{$max}px"]
            : [];
    }

    /** Un número seguido de `em`, dentro del rango de tracking. */
    private function checkEm(mixed $valor, string $donde): array
    {
        if (! is_string($valor) || ! preg_match('/^(-?\d+(?:\.\d+)?)em$/', $valor, $partes)) {
            return ["{$donde}: debe ir en em"];
        }

        [$min, $max] = self::RANGES['tracking'];
        $numero = (float) $partes[1];

        return $numero < $min || $numero > $max
            ? ["{$donde}: entre {$min} y {$max} em"]
            : [];
    }
}
