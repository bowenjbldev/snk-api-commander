<?php

namespace Tests\Unit;

use Packages\Kilkana\Database\Seeders\SnkKilkanaDemoBodyFactory;
use Packages\Kilkana\Database\Seeders\SnkKilkanaDemoProse;
use PHPUnit\Framework\TestCase;

/**
 * Un cuerpo de demo tiene que leerse como un articulo, no como una plantilla.
 *
 * Lo que se fija: no se repite un parrafo dentro de la pieza, dos piezas de la misma seccion no
 * son la misma con otro titular, el cierre no es una frase con la etiqueta dentro, y el largo es
 * el de un articulo y no el de un titular con dos frases.
 */
class SnkKilkanaDemoBodyFactoryTest extends TestCase
{
    /** @return array<string, mixed> */
    private function pieza(string $slug, string $cat = 'local', string $tier = 'free'): array
    {
        return [
            'slug' => $slug,
            'cat' => $cat,
            'title' => 'Titular de prueba',
            'excerpt' => 'Primera frase de la entradilla. Segunda frase, con mas detalle.',
            'tags' => ['pleno', 'movilidad'],
            'status' => 'published',
            'content_tier' => $tier,
        ];
    }

    private function parrafos(array $bloques): array
    {
        return array_values(array_map(
            fn (array $b) => $b['config']['text'],
            array_filter($bloques, fn (array $b) => $b['type'] === 'paragraph'),
        ));
    }

    public function test_ningun_parrafo_se_repite_dentro_de_la_pieza(): void
    {
        foreach (['a-b-c', 'pleno-de-julio', 'la-feria-vuelve', 'obras-en-la-ronda', 'x'] as $slug) {
            foreach (array_keys(SnkKilkanaDemoProse::MATERIAL) as $familia) {
                $cat = array_search($familia, SnkKilkanaDemoBodyFactory::FAMILIA, true);
                $parrafos = $this->parrafos(SnkKilkanaDemoBodyFactory::blocks($this->pieza($slug, $cat), 7));

                $this->assertSame(count($parrafos), count(array_unique($parrafos)), "{$familia}/{$slug} repite un parrafo.");
                $this->assertGreaterThanOrEqual(9, count($parrafos));
            }
        }
    }

    public function test_dos_piezas_de_la_misma_seccion_no_son_la_misma(): void
    {
        $una = $this->parrafos(SnkKilkanaDemoBodyFactory::blocks($this->pieza('el-pleno-aprueba-el-plan'), 7));
        $otra = $this->parrafos(SnkKilkanaDemoBodyFactory::blocks($this->pieza('los-vecinos-piden-plazos'), 7));

        // La entradilla es la misma en la prueba; lo demas casi no coincide.
        $comunes = count(array_intersect($una, $otra)) - 1;
        $this->assertLessThanOrEqual(3, $comunes, 'Dos piezas seguidas comparten como mucho tres parrafos de nueve.');
    }

    public function test_el_cierre_no_lleva_la_etiqueta_dentro_y_la_pieza_es_larga(): void
    {
        $bloques = SnkKilkanaDemoBodyFactory::blocks($this->pieza('slug-de-prueba'), 7);
        $texto = implode(' ', $this->parrafos($bloques));

        $this->assertStringNotContainsString('El seguimiento de pleno', $texto);
        $this->assertGreaterThan(200, count(preg_split('/\s+/u', trim($texto))), 'Un articulo, no un titular con dos frases.');

        $pie = collect($bloques)->firstWhere('type', 'image')['config']['caption'];
        $this->assertSame('Primera frase de la entradilla.', $pie);

        $recuadro = collect($bloques)->firstWhere('type', 'factbox');
        if ($recuadro !== null) {
            $this->assertStringNotContainsString('lecturas', $recuadro['config']['text']);
        }
    }

    /** El muro corta donde se lee gratis: una `locked` ensena entradilla y un parrafo. */
    public function test_el_muro_va_dentro_del_cuerpo(): void
    {
        $bloques = SnkKilkanaDemoBodyFactory::blocks($this->pieza('slug', 'local', 'locked'), 7);
        $tipos = array_column($bloques, 'type');

        $this->assertSame(3, array_search('paywall', $tipos, true));
    }
}
