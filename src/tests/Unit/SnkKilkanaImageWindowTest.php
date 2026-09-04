<?php

namespace Tests\Unit;

use Packages\Kilkana\Services\SnkKilkanaImageService;
use PHPUnit\Framework\TestCase;

/**
 * La geometría del encuadre, que es donde un píxel de desfase decapita: la ventana de una
 * proporción se centra en el foco, se empuja hasta caber, y nunca sale del rectángulo guardado.
 */
class SnkKilkanaImageWindowTest extends TestCase
{
    private const FULL = ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0];

    public function test_sin_proporcion_es_el_rectangulo_guardado_entero(): void
    {
        $this->assertSame(['x' => 0, 'y' => 0, 'w' => 1600, 'h' => 1000], SnkKilkanaImageService::window(1600, 1000, ['x' => 0.5, 'y' => 0.5], self::FULL, null));
        $this->assertSame(['x' => 160, 'y' => 100, 'w' => 800, 'h' => 500], SnkKilkanaImageService::window(1600, 1000, ['x' => 0.5, 'y' => 0.5], ['x' => 0.1, 'y' => 0.1, 'w' => 0.5, 'h' => 0.5], null));
    }

    public function test_la_ventana_se_centra_en_el_foco_y_se_empuja_hasta_caber(): void
    {
        // Apaisada 1600×1000 a cuadrado: la caja es 1000×1000 y se mueve en horizontal.
        $centro = SnkKilkanaImageService::window(1600, 1000, ['x' => 0.5, 'y' => 0.5], self::FULL, [1, 1]);
        $this->assertSame(['x' => 300, 'y' => 0, 'w' => 1000, 'h' => 1000], $centro);
        // El foco a la derecha del todo: la caja se pega al borde, no se sale.
        $derecha = SnkKilkanaImageService::window(1600, 1000, ['x' => 0.95, 'y' => 0.5], self::FULL, [1, 1]);
        $this->assertSame(['x' => 600, 'y' => 0, 'w' => 1000, 'h' => 1000], $derecha);
        // El foco arriba a la izquierda en un retrato 1000×1600 a 16/9: la caja es 1000×563 y sube.
        $arriba = SnkKilkanaImageService::window(1000, 1600, ['x' => 0.2, 'y' => 0.1], self::FULL, [16, 9]);
        $this->assertSame(['x' => 0, 'y' => 0, 'w' => 1000, 'h' => 563], $arriba);
    }

    public function test_el_rectangulo_guardado_manda_sobre_la_proporcion(): void
    {
        // Recorte al cuarto superior izquierdo (800×500) y proporción 3/2: la caja cabe entera en él.
        $crop = ['x' => 0.0, 'y' => 0.0, 'w' => 0.5, 'h' => 0.5];
        $win = SnkKilkanaImageService::window(1600, 1000, ['x' => 0.9, 'y' => 0.9], $crop, [3, 2]);
        $this->assertSame(['x' => 50, 'y' => 0, 'w' => 750, 'h' => 500], $win);
        $this->assertLessThanOrEqual(800, $win['x'] + $win['w']);
        $this->assertLessThanOrEqual(500, $win['y'] + $win['h']);
    }

    public function test_una_imagen_mas_estrecha_que_la_proporcion_no_revienta(): void
    {
        $win = SnkKilkanaImageService::window(300, 900, ['x' => 0.5, 'y' => 0.5], self::FULL, [16, 9]);
        $this->assertSame(300, $win['w']);
        $this->assertSame(169, $win['h']);
        $this->assertGreaterThanOrEqual(0, $win['y']);
    }
}
