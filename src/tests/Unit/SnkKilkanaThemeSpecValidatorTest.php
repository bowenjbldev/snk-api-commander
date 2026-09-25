<?php

namespace Tests\Unit;

use Packages\Kilkana\Services\SnkKilkanaThemeSpecValidator;
use Tests\TestCase;

/**
 * La puerta de entrada del catálogo.
 *
 * Estos casos son los que permiten producir temas deprisa sin que el catálogo se llene de temas
 * inservibles: un gris elegante que no llega al contraste mínimo se ve bien en una captura y
 * arruina la accesibilidad del periódico que lo instale.
 *
 * Dos clases de fallo se paran solo aquí, porque ninguna revienta al pintar: una rejilla cuyos
 * `span` no cuadran y una plantilla que se pasa del presupuesto de rendimiento.
 */
class SnkKilkanaThemeSpecValidatorTest extends TestCase
{
    private function validator(): SnkKilkanaThemeSpecValidator
    {
        return new SnkKilkanaThemeSpecValidator;
    }

    /** Los diez peldaños de la escala, obligatorios los diez. */
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
    private function row(array $columns, ?string $header = null): array
    {
        $row = ['cols' => array_map(
            fn (array $c) => ['span' => $c[0], 'blocks' => array_map(fn ($t) => ['type' => $t], $c[1])],
            $columns
        )];

        return $header === null ? $row : [...$row, 'header' => ['text' => $header]];
    }

    /** Un tema válido de referencia; cada caso lo estropea por un sitio distinto. */
    private function spec(array $changes = []): array
    {
        $spec = [
            'specVersion' => 2,
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

        foreach ($changes as $path => $value) {
            [$group, $key] = explode('.', $path);
            $spec[$group][$key] = $value;
        }

        return $spec;
    }

    public function test_the_shipped_shape_passes(): void
    {
        $this->assertSame([], $this->validator()->validate($this->spec()));
    }

    /**
     * Un spec de otra versión no se interpreta: se para en seco y se dice.
     *
     * Es el fallo más caro de los que hay aquí, porque no revienta. Un tema v1 leído como v2 no
     * tiene `grid` ni `type` ni `media`, así que **se ve mal** en el periódico de alguien, y eso
     * es peor que no publicarse. Además se devuelve SOLO ese error: los cuarenta que vendrían
     * detrás son ruido sobre el mismo hecho.
     */
    public function test_it_refuses_a_spec_from_another_version_and_says_only_that(): void
    {
        $spec = $this->spec();
        $spec['specVersion'] = 1;

        $errors = $this->validator()->validate($spec);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('specVersion', $errors[0]);
        $this->assertStringContainsString('it is migrated', $errors[0]);
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
     * desapercibido en la revisión y deja el periódico por debajo de AA.
     */
    public function test_it_refuses_a_theme_below_the_aa_contrast_ratio(): void
    {
        $errors = $this->validator()->validate($this->spec(['tokens.textSoft' => '#dddddd']));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('contrast textSoft on surface', $errors[0]);
        $this->assertStringContainsString('#dddddd', $errors[0], 'el error tiene que decir QUÉ color');
    }

    public function test_it_refuses_an_accent_that_cannot_be_read(): void
    {
        $errors = $this->validator()->validate($this->spec(['tokens.accent' => '#ffe066']));

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('accent', $errors[0]);
    }

    /** Un color que falta no puede pasar por bueno solo porque los demás estén. */
    public function test_it_refuses_a_missing_colour(): void
    {
        $spec = $this->spec();
        unset($spec['tokens']['accent']);

        $this->assertStringContainsString('tokens.accent', $this->validator()->validate($spec)[0]);
    }

    public function test_it_refuses_values_outside_the_same_ranges_the_inspector_allows(): void
    {
        $cases = [
            'tokens.pageRadius' => fn (array &$s) => $s['tokens']['pageRadius'] = '40px',
            'density.padX' => fn (array &$s) => $s['density']['padX'] = 99,
            'type.steps.hero.weight' => fn (array &$s) => $s['type']['steps']['hero']['weight'] = 650,
            'type.steps.card.tracking' => fn (array &$s) => $s['type']['steps']['card']['tracking'] = '0.9em',
            'grid.sheet' => fn (array &$s) => $s['grid']['sheet'] = 60,
        ];

        foreach ($cases as $what => $breakSpec) {
            $spec = $this->spec();
            $breakSpec($spec);

            $errors = $this->validator()->validate($spec);
            $this->assertNotSame([], $errors, "{$what} debería haber fallado");
        }
    }

    /**
     * Una apertura más pequeña que su tarjeta es siempre un error de tecleo.
     *
     * El Chapuzas las tiene IGUALES a propósito y eso vale; menor no lo hace nadie, y no se ve
     * hasta abrir la portada.
     */
    public function test_it_refuses_a_lead_smaller_than_its_card(): void
    {
        $spec = $this->spec();
        $spec['type']['steps']['hero']['size'] = 12;

        $this->assertStringContainsString('smaller than the card', implode(' ', $this->validator()->validate($spec)));
    }

    /** Los cinco recortes con nombre son lo que declara la altura de una foto: de ahí sale el CLS. */
    public function test_it_refuses_a_missing_named_crop(): void
    {
        $spec = $this->spec();
        unset($spec['media']['portrait']);

        $this->assertStringContainsString('media.portrait', $this->validator()->validate($spec)[0]);
    }

    /**
     * `columns` y `tracks` son excluyentes: con las dos no se sabe cuál manda al repartir.
     */
    public function test_it_refuses_a_grid_that_declares_columns_and_tracks(): void
    {
        $spec = $this->spec();
        $spec['grid']['tracks'] = [272, 272, 172, 302];

        $this->assertStringContainsString('exactly one', implode(' ', $this->validator()->validate($spec)));
    }

    /** Una fila que no llena la rejilla deja un hueco que nadie pidió, y no se ve hasta pintarla. */
    public function test_it_refuses_a_row_whose_spans_do_not_fill_the_grid(): void
    {
        $spec = $this->spec();
        $spec['layouts']['front'][1] = $this->row([[8, ['river']], [3, ['most-read']]]);

        $errors = $this->validator()->validate($spec);

        $this->assertStringContainsString('the spans add up to 11', $errors[0]);
        $this->assertStringContainsString('the grid has 12', $errors[0]);
    }

    public function test_it_refuses_a_column_without_blocks(): void
    {
        $spec = $this->spec();
        $spec['layouts']['front'][0]['cols'][0]['blocks'] = [];

        $this->assertStringContainsString('at least one block', $this->validator()->validate($spec)[0]);
    }

    /**
     * Un bloque desconocido es un tema mal hecho, no un dato viejo.
     *
     * El normalizador del editor descarta lo que no reconoce, y ahí está bien: protege de
     * composiciones guardadas hace meses. En el catálogo lo correcto es lo contrario, porque
     * un bloque que no existe significa que esa banda no se va a pintar y nadie lo sabría.
     */
    public function test_it_refuses_an_unknown_block_type(): void
    {
        $spec = $this->spec();
        $spec['layouts']['front'][] = $this->row([[12, ['weather']]]);

        $errors = $this->validator()->validate($spec);

        $this->assertStringContainsString('weather', $errors[0]);
        $this->assertStringContainsString('is not an editor block', $errors[0]);
    }

    /**
     * Los cuatro bloques compuestos tienen que entrar: `lead-briefs`, `brief-list`, `four-up` y
     * `mosaic`.
     *
     * La banda va la tercera y no la segunda a propósito: `lead-briefs` más `four-up` más
     * `mosaic` son diez fotos, y sobre el pliegue eso es justo lo que el presupuesto para.
     */
    public function test_it_accepts_the_blocks_added_in_v2(): void
    {
        $spec = $this->spec();
        $spec['layouts']['section'][] = $this->row([[12, ['brief-list']]]);
        $spec['layouts']['section'][] = $this->row([
            [6, ['lead-briefs', 'brief-list']],
            [6, ['four-up', 'mosaic']],
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
     * El presupuesto: un tema es un dato y se publica sin desplegar nada, así que es el sitio
     * exacto por donde un periódico se vuelve lento sin que nadie lo revise.
     *
     * Diecisiete bandas de cuatro tarjetas son 68 fotos: caben las bandas y caben los bloques,
     * y lo que se pasa es el peso de la página.
     */
    public function test_it_refuses_a_template_over_the_photo_budget(): void
    {
        $spec = $this->spec();
        $spec['layouts']['front'] = array_fill(0, 17, $this->row([[12, ['four-up']]]));

        $errors = $this->validator()->validate($spec);

        $this->assertStringContainsString('photos 68', $errors[0]);
        $this->assertStringContainsString('page weight', $errors[0]);
    }

    /**
     * Lo que compite con el LCP es lo que cargue por encima del pliegue, y el pliegue son las
     * dos primeras bandas. Aquí las fotos totales caben y lo que se pasa es dónde están.
     */
    public function test_it_refuses_too_many_photos_above_the_fold(): void
    {
        $spec = $this->spec();
        $spec['layouts']['front'] = [
            $this->row([[6, ['mosaic']], [6, ['mosaic']]]),
            $this->row([[12, ['lead-story']]]),
        ];

        $errors = $this->validator()->validate($spec);

        $this->assertStringContainsString('above the fold', $errors[0]);
        $this->assertStringContainsString('LCP', $errors[0]);
    }

    /** `photo: false` es lo que hace que una edición de teletipo no cuente fotos. */
    public function test_a_block_with_photos_switched_off_does_not_spend_budget(): void
    {
        $spec = $this->spec();
        $row = $this->row([[12, ['four-up']]]);
        $row['cols'][0]['blocks'][0]['config'] = ['photo' => false];
        $spec['layouts']['front'] = array_fill(0, 17, $row);

        $this->assertSame([], $this->validator()->validate($spec));
    }
}
