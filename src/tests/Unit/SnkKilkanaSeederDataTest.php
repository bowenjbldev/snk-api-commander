<?php

namespace Tests\Unit;

use Packages\Kilkana\Database\Seeders\SnkKilkanaDemoBodyFactory;
use Packages\Kilkana\Database\Seeders\SnkKilkanaDemoMediaLibrary;
use Packages\Kilkana\Enums\SnkKilkanaBlockTypeEnum;
use PHPUnit\Framework\TestCase;

/**
 * El snapshot de la demo, comprobado antes de que toque una base.
 *
 * Lo que se mira aquí es que el fichero sea SEMBRABLE: que cada pieza traiga los campos que el
 * seeder resuelve, que la clave pública no colisione y que el cuerpo que se le compone encima
 * hable el vocabulario que valida el controlador. Contar filas y estados no basta: un snapshot
 * cuyas piezas se siembran SIN CUERPO pasa esa cuenta en verde, porque nada mira `body_blocks`.
 */
class SnkKilkanaSeederDataTest extends TestCase
{
    /** Las secciones que declara el seeder. Duplicadas a mano: si divergen, esto se pone rojo. */
    private const CATEGORIES = [
        'local', 'comarca', 'municipios', 'barrios', 'politica', 'economia', 'agricultura',
        'empleo', 'deportes', 'futbol', 'polideportivo', 'cultura', 'patrimonio', 'agenda',
        'sucesos', 'opinion', 'medio-ambiente',
    ];

    /** Las firmas resolubles: plantilla más las tres que no lo son. */
    private const BYLINES = [
        'Marta Beneyto', 'Ramón Clavel', 'Iván Sagredo', 'Nuria Espí', 'Lucía Ferrándiz',
        'Óscar Tomás', 'Redacción', 'Agencia EFE', 'Josep Miralles',
    ];

    /** @return array<int, array<string, mixed>> */
    private function snapshot(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/../../packages/Kilkana/database/seeders/data/posts.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    public function test_the_posts_snapshot_is_seedable_and_complete(): void
    {
        $posts = $this->snapshot();

        $this->assertCount(532, $posts);
        $statusCounts = array_count_values(array_column($posts, 'status'));
        ksort($statusCounts);

        $this->assertSame([
            'draft' => 35,
            'published' => 475,
            'review' => 19,
            'scheduled' => 3,
        ], $statusCounts);

        $identities = [];

        foreach ($posts as $post) {
            $this->assertSame(
                [],
                array_diff(
                    ['category', 'title', 'excerpt', 'author', 'publish_at', 'reads', 'image', 'status', 'content_tier', 'slug', 'tags'],
                    array_keys($post),
                ),
            );
            $this->assertContains($post['status'], ['draft', 'review', 'scheduled', 'published']);
            $this->assertContains($post['content_tier'], ['free', 'metered', 'locked']);

            $urlDate = $post['status'] === 'published' ? substr($post['publish_at'], 0, 10) : 'unpublished';
            $identities[] = $urlDate.'|'.$post['slug'];
        }

        $this->assertCount(532, array_unique($identities));
    }

    /**
     * Cada pieza apunta a una sección y a una firma que el seeder sabe resolver.
     *
     * `firstOrFail` sobre la categoría revienta la siembra entera a media tabla; una firma que
     * no esté en ningún mapa es peor porque NO revienta: `authorFor` le inventa una cuenta
     * `@eldiario.example` con rol de redactora, y la demo se llena de plantilla fantasma.
     */
    public function test_each_post_points_to_an_existing_section_and_byline(): void
    {
        foreach ($this->snapshot() as $post) {
            $this->assertContains($post['category'], self::CATEGORIES, "sección desconocida en «{$post['slug']}»");
            $this->assertContains($post['author'], self::BYLINES, "firma desconocida en «{$post['slug']}»");
        }
    }

    /**
     * El slug corta en el hueco entre palabras, no en medio de una.
     *
     * Es la mitad de la URL pública que significa algo, y es permanente: `…plan-de-movilidad-de`
     * no se arregla después sin dejar un 301 detrás. La regla la escribe `slugify` en el
     * frontend y esta prueba comprueba el RESULTADO, que es lo único que comparten los dos lados.
     */
    public function test_no_slug_cuts_a_word_in_half(): void
    {
        foreach ($this->snapshot() as $post) {
            $this->assertLessThanOrEqual(52, strlen($post['slug']));
            $this->assertMatchesRegularExpression('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $post['slug']);

            // Reconstruir el slug largo y comprobar que el corte cayó en un guion: si el
            // carácter siguiente no lo es, se partió una palabra.
            $full = $this->longSlug($post['title']);
            if (strlen($full) > strlen($post['slug'])) {
                $this->assertSame(
                    '-',
                    $full[strlen($post['slug'])],
                    "«{$post['slug']}» corta «{$full}» a media palabra",
                );
            }
        }
    }

    /**
     * La fototeca de la demo sale del disco, y el disco tiene que estar completo.
     *
     * Una fila de medios que solo trae la dirección de un servicio de terceros llega sin ancho,
     * sin alto, sin peso y sin `content_hash`: la mediateca no puede deduplicar ni filtrar por
     * orientación, y el lector paga DNS, TLS y una cookie ajena por cada foto. Aquí se mira el
     * material ANTES de sembrar: que cada JPEG esté, que se pueda leer y que diga sus medidas.
     */
    public function test_the_demo_photo_library_is_complete_and_has_dimensions(): void
    {
        $photoLibrary = new SnkKilkanaDemoMediaLibrary;

        $this->assertSame(60, $photoLibrary->size(), 'faltan fotos en database/seeders/data/media');

        foreach (glob(__DIR__.'/../../packages/Kilkana/database/seeders/data/media/*.jpg') ?: [] as $file) {
            $dimensions = @getimagesize($file);
            $name = basename($file);

            $this->assertNotFalse($dimensions, "«{$name}» no se lee como imagen");
            $this->assertGreaterThan(0, $dimensions[0], "«{$name}» no declara ancho");
            $this->assertGreaterThan(0, $dimensions[1], "«{$name}» no declara alto");
            $this->assertSame('image/jpeg', $dimensions['mime'], "«{$name}» no es un JPEG");
        }
    }

    /**
     * Cada portada del snapshot cae en un fichero que existe, y siempre en el mismo.
     *
     * El reparto es `crc32` de la dirección original: dos piezas con la misma dirección comparten
     * foto, y una re-siembra no renumera la biblioteca. Lo que esto impide es lo único que puede
     * salir mal —una dirección que no encuentre fichero— y que el reparto deje de ser
     * determinista, que se vería como una mediateca distinta después de cada `--fresh`.
     */
    public function test_each_cover_lands_on_an_existing_photo_and_always_the_same_one(): void
    {
        $photoLibrary = new SnkKilkanaDemoMediaLibrary;
        $urls = array_values(array_unique(array_filter(array_column($this->snapshot(), 'image'))));

        $this->assertSame(186, count($urls));

        $used = [];
        foreach ($urls as $url) {
            $file = $photoLibrary->fileFor($url);

            $this->assertNotNull($file, "«{$url}» no encuentra foto");
            $this->assertFileExists($file);
            $this->assertSame($file, $photoLibrary->fileFor($url), 'el reparto no es determinista');
            $used[$file] = true;
        }

        // Sesenta ficheros para 186 direcciones: cuatro fotos se quedan sin reclamar. Entran en
        // la biblioteca igual (`seedAll`), y son las que dan filas a las dos vistas que existen
        // para encontrar trabajo pendiente: «sin usar» y «Missing alt».
        $this->assertSame(56, count($used));
        $this->assertSame(60, $photoLibrary->size());
    }

    /**
     * El cuerpo que se compone encima del snapshot es sembrable y significa algo.
     *
     * Sin esta prueba, un snapshot sin cuerpo pasa en verde: piezas sin cuerpo son artículos en
     * blanco y un `content_tier` que no corta por ningún sitio.
     */
    public function test_the_generated_body_uses_the_contract_vocabulary(): void
    {
        $types = SnkKilkanaBlockTypeEnum::values();
        $withPaywall = 0;

        foreach ($this->snapshot() as $post) {
            $blocks = SnkKilkanaDemoBodyFactory::blocks($post, 7);

            $this->assertNotEmpty($blocks, "«{$post['slug']}» se quedó sin cuerpo");
            $this->assertSame(
                array_map(fn (int $n) => 'b'.($n + 1), array_keys($blocks)),
                array_column($blocks, 'id'),
                "los ids de «{$post['slug']}» no son correlativos",
            );

            $paywalls = 0;
            foreach ($blocks as $block) {
                $this->assertContains($block['type'], $types, "tipo fuera del contrato en «{$post['slug']}»");
                $this->assertIsArray($block['config']);
                $paywalls += (int) ($block['type'] === 'paywall');
            }

            // Un artículo corta por un solo sitio: dos muros no son dos cortes, son un cuerpo
            // que no se sabe dónde acaba de estar abierto.
            $this->assertLessThanOrEqual(1, $paywalls, "«{$post['slug']}» lleva dos muros");

            if ($post['content_tier'] === 'free') {
                $this->assertSame(0, $paywalls, "una pieza abierta no lleva muro: «{$post['slug']}»");
            } else {
                $this->assertSame(1, $paywalls, "«{$post['slug']}» es {$post['content_tier']} y no tiene muro");
                $withPaywall++;
            }

            // El muro tiene que dejar algo delante y algo detrás, o no corta nada: sin teaser
            // la pieza pública sale vacía, y sin resto no hay nada que cerrar.
            $at = array_search('paywall', array_column($blocks, 'type'), true);
            if ($at !== false) {
                $this->assertGreaterThan(0, $at, "el muro de «{$post['slug']}» no deja teaser");
                $this->assertLessThan(count($blocks) - 1, $at, "el muro de «{$post['slug']}» no cierra nada");
            }
        }

        $this->assertSame(143, $withPaywall);
    }

    /** El mismo slug da el mismo cuerpo: dos siembras no pueden dar bases distintas. */
    public function test_the_generated_body_is_deterministic(): void
    {
        $post = $this->snapshot()[0];

        $this->assertSame(
            SnkKilkanaDemoBodyFactory::blocks($post, 7),
            SnkKilkanaDemoBodyFactory::blocks($post, 7),
        );
    }

    /** La misma regla que `src/lib/data/slugify.ts`, sin el corte. */
    private function longSlug(string $title): string
    {
        $plain = preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize(mb_strtolower($title), \Normalizer::FORM_D));

        return preg_replace('/\s+/', '-', trim(preg_replace('/[^a-z0-9 -]/', '', $plain)));
    }
}
