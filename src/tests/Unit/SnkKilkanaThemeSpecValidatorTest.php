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
 */
class SnkKilkanaThemeSpecValidatorTest extends TestCase
{
    private function validator(): SnkKilkanaThemeSpecValidator
    {
        return new SnkKilkanaThemeSpecValidator;
    }

    /** Un tema valido de referencia; cada caso lo estropea por un sitio distinto. */
    private function spec(array $cambios = []): array
    {
        $spec = [
            'masthead' => 'daily',
            'tokens' => [
                'brand' => '#121212', 'brandDark' => '#000000', 'brandSoft' => '#f2f2f2',
                'brandEdge' => '#dcdcdc', 'accent' => '#d32f2f', 'text' => '#1a1a1a',
                'textMuted' => '#717171', 'textSoft' => '#757575', 'textFaint' => '#c4c4c4',
                'surface' => '#ffffff', 'surface2' => '#f9f9f9', 'surface3' => '#efefef',
                'borderSubtle' => '#efefef', 'borderSoft' => '#e4e4e4', 'borderStrong' => '#bfc7ce',
                'pageRadius' => '0px', 'fontSerif' => 'Geist', 'fontSans' => 'Geist',
            ],
            'style' => [
                'headlineWeight' => 700, 'headlineTracking' => '-0.015em', 'headlineScale' => 1,
                'blockPadX' => '18px', 'blockPadY' => '13px',
                'kicker' => [
                    'bg' => 'transparent', 'color' => 'var(--theme-accent)', 'pad' => '0',
                    'radius' => '0', 'transform' => 'none', 'weight' => '700',
                    'tracking' => '0.02em', 'size' => '11px',
                ],
                'card' => ['bg' => 'transparent', 'border' => 'none', 'pad' => '0', 'radius' => '0', 'shadow' => 'none'],
            ],
            'layouts' => [
                'front' => [['type' => 'lead-story', 'header' => null]],
                'section' => [['type' => 'lead-story'], ['cols' => [['type' => 'river'], ['type' => 'most-read']]]],
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

    public function test_it_refuses_values_outside_the_same_ranges_the_inspector_allows(): void
    {
        foreach ([
            'style.headlineWeight' => 650,
            'style.headlineScale' => 3,
            'style.blockPadX' => '99px',
            'tokens.pageRadius' => '40px',
        ] as $ruta => $valor) {
            $errores = $this->validator()->validate($this->spec([$ruta => $valor]));

            $this->assertNotSame([], $errores, "{$ruta} deberia haber fallado");
            $this->assertStringContainsString(explode('.', $ruta)[1], implode(' ', $errores));
        }
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
        $spec['layouts']['front'][] = ['type' => 'weather'];

        $errores = $this->validator()->validate($spec);

        $this->assertStringContainsString('weather', $errores[0]);
        $this->assertStringContainsString('no es un bloque conocido', $errores[0]);
    }

    public function test_it_refuses_an_unknown_block_inside_a_column(): void
    {
        $spec = $this->spec();
        $spec['layouts']['section'][1]['cols'][0] = ['type' => 'tags'];

        $this->assertStringContainsString('tags', $this->validator()->validate($spec)[0]);
    }

    public function test_it_refuses_a_theme_without_its_starting_templates(): void
    {
        $spec = $this->spec();
        unset($spec['layouts']['section']);

        $this->assertStringContainsString('layouts.section', $this->validator()->validate($spec)[0]);
    }

    /** Un color que falta no puede pasar por bueno solo porque los demas esten. */
    public function test_it_refuses_a_missing_colour(): void
    {
        $spec = $this->spec();
        unset($spec['tokens']['accent']);

        $this->assertStringContainsString('tokens.accent', $this->validator()->validate($spec)[0]);
    }
}
