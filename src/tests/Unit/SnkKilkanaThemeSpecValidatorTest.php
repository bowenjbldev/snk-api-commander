<?php

namespace Tests\Unit;

use Packages\Kilkana\Services\SnkKilkanaThemeSpecValidator;
use Tests\TestCase;

/**
 * La puerta de entrada del catalogo.
 *
 * Estos casos son los que permiten producir temas deprisa sin que el catalogo se llene de temas
 * inservibles: un gris elegante que no llega al contraste minimo se ve bien en una captura y
 * arruina la accesibilidad del periodico que lo instale.
 *
 * Dos clases de fallo se paran solo aqui, porque ninguna revienta al pintar: una rejilla cuyos
 * `span` no cuadran y una plantilla que se pasa del presupuesto de rendimiento.
 */
class SnkKilkanaThemeSpecValidatorTest extends TestCase
{
    private function validator(): SnkKilkanaThemeSpecValidator
    {
        return new SnkKilkanaThemeSpecValidator;
    }

    /** Los diez peldanos de la escala, obligatorios los diez. */
    private function steps(): array
    {
        $base = ['weight' => 700, 'tracking' => '-0.01em', 'leading' => 1.2];

        return [
            'hero' => [...$base, 'size' => 54],
            'lead' => [...$base, 'size' => 30],
            'card' => [...$base, 'size' => 20],
            'brief' => [...$base, 'size' => 15],
            'kicker' => [...$base, 'size' => 11],
            'standfirst' => [...$base, 'size' => 17],
            'meta' => [...$base, 'size' => 12],
            'band' => [...$base, 'size' => 18],
            'masthead' => [...$base, 'size' => 46],
            'nav' => [...$base, 'size' => 12],
        ];
    }

    /** Una fila: los bloques de cada columna, con su ancho. */
    private function row(array $columnas, ?string $rotulo = null): array
    {
        $fila = ['cols' => array_map(
            fn (array $c) => ['span' => $c[0], 'blocks' => array_map(fn ($t) => ['type' => $t], $c[1])],
            $columnas
        )];

        return $rotulo === null ? $fila : [...$fila, 'header' => ['text' => $rotulo]];
    }

    /** Un tema valido de referencia; cada caso lo estropea por un sitio distinto. */
    private function spec(array $cambios = []): array
    {
        $spec = [
            'specVersion' => 2,
            'masthead' => 'daily',
            'grid' => ['sheet' => 1200, 'columns' => 12, 'gutter' => 24],
            'tokens' => [
                'brand' => '#121212', 'brandDark' => '#000000', 'brandSoft' => '#f2f2f2',
                'brandEdge' => '#dcdcdc', 'accent' => '#d32f2f', 'text' => '#1a1a1a',
                'textMuted' => '#717171', 'textSoft' => '#757575', 'textFaint' => '#c4c4c4',
                'surface' => '#ffffff', 'surface2' => '#f9f9f9', 'surface3' => '#efefef',
                'borderSubtle' => '#efefef', 'borderSoft' => '#e4e4e4', 'borderStrong' => '#bfc7ce',
                'pageRadius' => '0px',
            ],
            'type' => ['display' => 'Georgia, serif', 'text' => 'Geist, sans-serif', 'steps' => $this->steps()],
            'media' => ['hero' => '16/9', 'card' => '3/2', 'thumb' => '4/3', 'portrait' => '2/3', 'square' => '1/1'],
            'density' => ['rowGap' => 44, 'blockGap' => 18, 'padX' => 28, 'padY' => 20],
            'chrome' => [
                'kicker' => ['bg' => 'transparent', 'pad' => '0', 'radius' => '0'],
                'card' => ['bg' => 'transparent', 'border' => 'none', 'pad' => '0', 'radius' => '0', 'shadow' => 'none'],
            ],
            'layouts' => [
                'front' => [
                    $this->row([[12, ['lead-story']]]),
                    $this->row([[8, ['river']], [4, ['most-read', 'ad-slot']]], 'Ultima hora'),
                ],
                'section' => [$this->row([[12, ['lead-story']]])],
            ],
        ];

        foreach ($cambios as $ruta => $valor) {
            [$grupo, $clave] = explode('.', $ruta);
            $spec[$grupo][$clave] = $valor;
        }

        return $spec;
    }

    public function test_the_shipped_shape_passes(): void
    {
        $this->assertSame([], $this->validator()->validate($this->spec()));
    }

    /**
     * Un spec de otra version no se interpreta: se para en seco y se dice.
     *
     * Es el fallo mas caro de los que hay aqui, porque no revienta. Un tema v1 leido como v2 no
     * tiene `grid` ni `type` ni `media`, asi que **se ve mal** en el periodico de alguien, y eso
     * es peor que no publicarse. Ademas se devuelve SOLO ese error: los cuarenta que vendrian
     * detras son ruido sobre el mismo hecho.
     */
    public function test_it_refuses_a_spec_from_another_version_and_says_only_that(): void
    {
        $spec = $this->spec();
        $spec['specVersion'] = 1;

        $errores = $this->validator()->validate($spec);

        $this->assertCount(1, $errores);
        $this->assertStringContainsString('specVersion', $errores[0]);
        $this->assertStringContainsString('se migra', $errores[0]);
    }

    public function test_it_refuses_a_spec_without_a_version(): void
    {
        $spec = $this->spec();
        unset($spec['specVersion']);

        $this->assertStringContainsString('specVersion', $this->validator()->validate($spec)[0]);
    }

    /**
     * El caso que justifica todo el validador.
     *
     * Un gris claro sobre blanco es exactamente lo que sale cuando se pide «sobrio»: pasa
     * desapercibido en la revision y deja el periodico por debajo de AA.
     */
    public function test_it_refuses_a_theme_below_the_aa_contrast_ratio(): void
    {
        $errores = $this->validator()->validate($this->spec(['tokens.textSoft' => '#dddddd']));

        $this->assertCount(1, $errores);
        $this->assertStringContainsString('contraste textSoft sobre surface', $errores[0]);
        $this->assertStringContainsString('#dddddd', $errores[0], 'el error tiene que decir QUE color');
    }

    public function test_it_refuses_an_accent_that_cannot_be_read(): void
    {
        $errores = $this->validator()->validate($this->spec(['tokens.accent' => '#ffe066']));

        $this->assertNotSame([], $errores);
        $this->assertStringContainsString('accent', $errores[0]);
    }

    public function test_it_refuses_a_masthead_that_no_component_can_paint(): void
    {
        $spec = $this->spec();
        $spec['masthead'] = 'neon';

        $this->assertStringContainsString('masthead', $this->validator()->validate($spec)[0]);
    }

    /** Un color que falta no puede pasar por bueno solo porque los demas esten. */
    public function test_it_refuses_a_missing_colour(): void
    {
        $spec = $this->spec();
        unset($spec['tokens']['accent']);

        $this->assertStringContainsString('tokens.accent', $this->validator()->validate($spec)[0]);
    }

    public function test_it_refuses_values_outside_the_same_ranges_the_inspector_allows(): void
    {
        $casos = [
            'tokens.pageRadius' => fn (array &$s) => $s['tokens']['pageRadius'] = '40px',
            'density.padX' => fn (array &$s) => $s['density']['padX'] = 99,
            'type.steps.hero.weight' => fn (array &$s) => $s['type']['steps']['hero']['weight'] = 650,
            'type.steps.card.tracking' => fn (array &$s) => $s['type']['steps']['card']['tracking'] = '0.9em',
            'grid.sheet' => fn (array &$s) => $s['grid']['sheet'] = 60,
        ];

        foreach ($casos as $que => $estropear) {
            $spec = $this->spec();
            $estropear($spec);

            $errores = $this->validator()->validate($spec);
            $this->assertNotSame([], $errores, "{$que} deberia haber fallado");
        }
    }

    /**
     * Una apertura mas pequena que su tarjeta es siempre un error de tecleo.
     *
     * El Chapuzas las tiene IGUALES a proposito y eso vale; menor no lo hace nadie, y no se ve
     * hasta abrir la portada.
     */
    public function test_it_refuses_a_lead_smaller_than_its_card(): void
    {
        $spec = $this->spec();
        $spec['type']['steps']['hero']['size'] = 12;

        $this->assertStringContainsString('menor que la tarjeta', implode(' ', $this->validator()->validate($spec)));
    }

    /** Los cinco recortes con nombre son lo que declara la altura de una foto: de ahi sale el CLS. */
    public function test_it_refuses_a_missing_named_crop(): void
    {
        $spec = $this->spec();
        unset($spec['media']['portrait']);

        $this->assertStringContainsString('media.portrait', $this->validator()->validate($spec)[0]);
    }

    /**
     * `columns` y `tracks` son excluyentes: con las dos no se sabe cual manda al repartir.
     */
    public function test_it_refuses_a_grid_that_declares_columns_and_tracks(): void
    {
        $spec = $this->spec();
        $spec['grid']['tracks'] = [272, 272, 172, 302];

        $this->assertStringContainsString('exactamente uno', implode(' ', $this->validator()->validate($spec)));
    }

    /** Una fila que no llena la rejilla deja un hueco que nadie pidio, y no se ve hasta pintarla. */
    public function test_it_refuses_a_row_whose_spans_do_not_fill_the_grid(): void
    {
        $spec = $this->spec();
        $spec['layouts']['front'][1] = $this->row([[8, ['river']], [3, ['most-read']]]);

        $errores = $this->validator()->validate($spec);

        $this->assertStringContainsString('los span suman 11', $errores[0]);
        $this->assertStringContainsString('la rejilla tiene 12', $errores[0]);
    }

    public function test_it_refuses_a_column_without_blocks(): void
    {
        $spec = $this->spec();
        $spec['layouts']['front'][0]['cols'][0]['blocks'] = [];

        $this->assertStringContainsString('al menos un bloque', $this->validator()->validate($spec)[0]);
    }

    /**
     * Un bloque desconocido es un tema mal hecho, no un dato viejo.
     *
     * El normalizador del editor descarta lo que no reconoce, y ahi esta bien: protege de
     * composiciones guardadas hace meses. En el catalogo lo correcto es lo contrario, porque
     * un bloque que no existe significa que esa banda no se va a pintar y nadie lo sabria.
     */
    public function test_it_refuses_an_unknown_block_type(): void
    {
        $spec = $this->spec();
        $spec['layouts']['front'][] = $this->row([[12, ['weather']]]);

        $errores = $this->validator()->validate($spec);

        $this->assertStringContainsString('weather', $errores[0]);
        $this->assertStringContainsString('no es un bloque del editor', $errores[0]);
    }

    /**
     * Los cinco bloques compuestos tienen que entrar: `lead-briefs`, `brief-list`, `four-up`,
     * `mosaic` y `topic-strip`.
     *
     * La banda va la tercera y no la segunda a proposito: `lead-briefs` mas `four-up` mas
     * `mosaic` son diez fotos, y sobre el pliegue eso es justo lo que el presupuesto para.
     */
    public function test_it_accepts_the_blocks_the_v2_added(): void
    {
        $spec = $this->spec();
        $spec['layouts']['section'][] = $this->row([[12, ['brief-list']]]);
        $spec['layouts']['section'][] = $this->row([
            [6, ['lead-briefs', 'brief-list']],
            [6, ['four-up', 'mosaic', 'topic-strip']],
        ]);

        $this->assertSame([], $this->validator()->validate($spec));
    }

    public function test_it_refuses_a_theme_without_its_starting_templates(): void
    {
        $spec = $this->spec();
        unset($spec['layouts']['section']);

        $this->assertStringContainsString('layouts.section', $this->validator()->validate($spec)[0]);
    }

    /**
     * El presupuesto: un tema es un dato y se publica sin desplegar nada, asi que es el sitio
     * exacto por donde un periodico se vuelve lento sin que nadie lo revise.
     *
     * Diecisiete bandas de cuatro tarjetas son 68 fotos: caben las bandas y caben los bloques,
     * y lo que se pasa es el peso de la pagina.
     */
    public function test_it_refuses_a_template_over_the_photo_budget(): void
    {
        $spec = $this->spec();
        $spec['layouts']['front'] = array_fill(0, 17, $this->row([[12, ['four-up']]]));

        $errores = $this->validator()->validate($spec);

        $this->assertStringContainsString('fotos 68', $errores[0]);
        $this->assertStringContainsString('peso de la pagina', str_replace('á', 'a', $errores[0]));
    }

    /**
     * Lo que compite con el LCP es lo que cargue por encima del pliegue, y el pliegue son las
     * dos primeras bandas. Aqui las fotos totales caben y lo que se pasa es donde estan.
     */
    public function test_it_refuses_too_many_photos_above_the_fold(): void
    {
        $spec = $this->spec();
        $spec['layouts']['front'] = [
            $this->row([[6, ['mosaic']], [6, ['mosaic']]]),
            $this->row([[12, ['lead-story']]]),
        ];

        $errores = $this->validator()->validate($spec);

        $this->assertStringContainsString('pliegue', $errores[0]);
        $this->assertStringContainsString('LCP', $errores[0]);
    }

    /** `photo: false` es lo que hace que una edicion de teletipo no cuente fotos. */
    public function test_a_block_with_photos_switched_off_does_not_spend_budget(): void
    {
        $spec = $this->spec();
        $fila = $this->row([[12, ['four-up']]]);
        $fila['cols'][0]['blocks'][0]['config'] = ['photo' => false];
        $spec['layouts']['front'] = array_fill(0, 17, $fila);

        $this->assertSame([], $this->validator()->validate($spec));
    }
}
