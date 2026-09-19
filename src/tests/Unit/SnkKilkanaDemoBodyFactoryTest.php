<?php

namespace Tests\Unit;

use Packages\Kilkana\Database\Seeders\SnkKilkanaDemoBodyFactory;
use Packages\Kilkana\Database\Seeders\SnkKilkanaDemoProse;
use PHPUnit\Framework\TestCase;

/**
 * Un cuerpo de demo tiene que leerse como un artículo, no como una plantilla.
 *
 * Lo que se fija: no se repite un párrafo dentro de la pieza, dos piezas de la misma sección no
 * son la misma con otro titular, el cierre no es una frase con la etiqueta dentro, y el largo es
 * el de un artículo y no el de un titular con dos frases.
 */
class SnkKilkanaDemoBodyFactoryTest extends TestCase
{
    /** @return array<string, mixed> */
    private function makePost(string $slug, string $category = 'local', string $tier = 'free'): array
    {
        return [
            'slug' => $slug,
            'category' => $category,
            'title' => 'Titular de prueba',
            'excerpt' => 'Primera frase de la entradilla. Segunda frase, con mas detalle.',
            'tags' => ['pleno', 'movilidad'],
            'status' => 'published',
            'content_tier' => $tier,
        ];
    }

    private function paragraphs(array $blocks): array
    {
        return array_values(array_map(
            fn (array $b) => $b['config']['text'],
            array_filter($blocks, fn (array $b) => $b['type'] === 'paragraph'),
        ));
    }

    public function test_no_paragraph_repeats_within_a_post(): void
    {
        foreach (['a-b-c', 'pleno-de-julio', 'la-feria-vuelve', 'obras-en-la-ronda', 'x'] as $slug) {
            foreach (array_keys(SnkKilkanaDemoProse::MATERIAL) as $family) {
                $category = array_search($family, SnkKilkanaDemoBodyFactory::FAMILY_OF, true);
                $paragraphs = $this->paragraphs(SnkKilkanaDemoBodyFactory::blocks($this->makePost($slug, $category), 7));

                $this->assertSame(count($paragraphs), count(array_unique($paragraphs)), "{$family}/{$slug} repite un párrafo.");
                $this->assertGreaterThanOrEqual(9, count($paragraphs));
            }
        }
    }

    public function test_two_posts_from_the_same_section_differ(): void
    {
        $one = $this->paragraphs(SnkKilkanaDemoBodyFactory::blocks($this->makePost('el-pleno-aprueba-el-plan'), 7));
        $other = $this->paragraphs(SnkKilkanaDemoBodyFactory::blocks($this->makePost('los-vecinos-piden-plazos'), 7));

        // La entradilla es la misma en la prueba; lo demás casi no coincide.
        $shared = count(array_intersect($one, $other)) - 1;
        $this->assertLessThanOrEqual(3, $shared, 'Dos piezas seguidas comparten como mucho tres párrafos de nueve.');
    }

    public function test_the_closing_does_not_contain_the_tag_and_the_post_is_long(): void
    {
        $blocks = SnkKilkanaDemoBodyFactory::blocks($this->makePost('slug-de-prueba'), 7);
        $text = implode(' ', $this->paragraphs($blocks));

        $this->assertStringNotContainsString('El seguimiento de pleno', $text);
        $this->assertGreaterThan(200, count(preg_split('/\s+/u', trim($text))), 'Un artículo, no un titular con dos frases.');

        $caption = collect($blocks)->firstWhere('type', 'image')['config']['caption'];
        $this->assertSame('Primera frase de la entradilla.', $caption);

        $factbox = collect($blocks)->firstWhere('type', 'factbox');
        if ($factbox !== null) {
            $this->assertStringNotContainsString('lecturas', $factbox['config']['text']);
        }
    }

    /** El muro corta donde se lee gratis: una `locked` enseña entradilla y un párrafo. */
    public function test_the_paywall_sits_inside_the_body(): void
    {
        $blocks = SnkKilkanaDemoBodyFactory::blocks($this->makePost('slug', 'local', 'locked'), 7);
        $types = array_column($blocks, 'type');

        $this->assertSame(3, array_search('paywall', $types, true));
    }
}
