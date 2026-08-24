<?php

namespace Tests\Unit;

use Packages\Kilkana\Database\Seeders\SnkKilkanaDemoBodyFactory;
use Packages\Kilkana\Database\Seeders\SnkKilkanaDemoMediaLibrary;
use Packages\Kilkana\Enums\SnkKilkanaBlockTypeEnum;
use PHPUnit\Framework\TestCase;

/**
 * El snapshot de la demo, comprobado antes de que toque una base.
 *
 * Lo que se mira aqui es que el fichero sea SEMBRABLE: que cada pieza traiga los campos que el
 * seeder resuelve, que la clave publica no colisione y que el cuerpo que se le compone encima
 * hable el vocabulario que valida el controlador. La version anterior solo contaba filas y
 * estados, y por eso paso en verde el dia que las 522 piezas se sembraron SIN CUERPO: nada
 * miraba `body_blocks` porque el snapshot nunca lo trajo.
 */
class SnkKilkanaSeederDataTest extends TestCase
{
    /** Las secciones que declara el seeder. Duplicadas a mano: si divergen, esto se pone rojo. */
    private const CATEGORIAS = [
        'local', 'comarca', 'municipios', 'barrios', 'politica', 'economia', 'agricultura',
        'empleo', 'deportes', 'futbol', 'polideportivo', 'cultura', 'patrimonio', 'agenda',
        'sucesos', 'opinion', 'medio-ambiente',
    ];

    /** Las firmas resolubles: plantilla mas las tres que no lo son. */
    private const FIRMAS = [
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

    public function test_snapshot_de_posts_de_kilkana_es_sembrable_y_completo(): void
    {
        $posts = $this->snapshot();

        $this->assertCount(522, $posts);
        $statusCounts = array_count_values(array_column($posts, 'status'));
        ksort($statusCounts);

        $this->assertSame([
            'draft' => 35,
            'published' => 465,
            'review' => 19,
            'scheduled' => 3,
        ], $statusCounts);

        $identities = [];

        foreach ($posts as $post) {
            $this->assertSame(
                [],
                array_diff(
                    ['cat', 'title', 'excerpt', 'author', 'publish_at', 'reads', 'image', 'status', 'content_tier', 'slug', 'tags'],
                    array_keys($post),
                ),
            );
            $this->assertContains($post['status'], ['draft', 'review', 'scheduled', 'published']);
            $this->assertContains($post['content_tier'], ['free', 'metered', 'locked']);

            $urlDate = $post['status'] === 'published' ? substr($post['publish_at'], 0, 10) : 'unpublished';
            $identities[] = $urlDate.'|'.$post['slug'];
        }

        $this->assertCount(522, array_unique($identities));
    }

    /**
     * Cada pieza apunta a una seccion y a una firma que el seeder sabe resolver.
     *
     * `firstOrFail` sobre la categoria revienta la siembra entera a media tabla; una firma que
     * no este en ningun mapa es peor porque NO revienta: `authorFor` le inventa una cuenta
     * `@eldiario.example` con rol de redactora, y la demo se llena de plantilla fantasma.
     */
    public function test_cada_pieza_apunta_a_una_seccion_y_a_una_firma_que_existen(): void
    {
        foreach ($this->snapshot() as $post) {
            $this->assertContains($post['cat'], self::CATEGORIAS, "sección desconocida en «{$post['slug']}»");
            $this->assertContains($post['author'], self::FIRMAS, "firma desconocida en «{$post['slug']}»");
        }
    }

    /**
     * El slug corta en el hueco entre palabras, no en medio de una.
     *
     * Es la mitad de la URL publica que significa algo, y es permanente: `…plan-de-movilidad-de`
     * no se arregla despues sin dejar un 301 detras. La regla la escribe `slugify` en el
     * frontend y esta prueba comprueba el RESULTADO, que es lo unico que comparten los dos lados.
     */
    public function test_ningun_slug_corta_una_palabra_por_la_mitad(): void
    {
        foreach ($this->snapshot() as $post) {
            $this->assertLessThanOrEqual(52, strlen($post['slug']));
            $this->assertMatchesRegularExpression('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $post['slug']);

            // Reconstruir el slug largo y comprobar que el corte cayo en un guion: si el
            // caracter siguiente no lo es, se partio una palabra.
            $completo = $this->slugLargo($post['title']);
            if (strlen($completo) > strlen($post['slug'])) {
                $this->assertSame(
                    '-',
                    $completo[strlen($post['slug'])],
                    "«{$post['slug']}» corta «{$completo}» a media palabra",
                );
            }
        }
    }

    /**
     * El cuerpo que se compone encima del snapshot es sembrable y significa algo.
     *
     * Sin esta prueba, F-2 volveria a pasar en verde: 522 piezas sin cuerpo son 522 articulos en
     * blanco y un `content_tier` que no corta por ningun sitio.
     */
    /**
     * La fototeca de la demo sale del disco, y el disco tiene que estar completo.
     *
     * Esto es lo que faltaba el dia que las 176 filas de medios se sembraron con una direccion
     * de un servicio de terceros y nada mas: sin ancho, sin alto, sin peso y sin `content_hash`,
     * asi que la mediateca no podia deduplicar ni filtrar por orientacion, y el lector pagaba
     * DNS, TLS y una cookie ajena por cada foto. Aqui se mira el material ANTES de sembrar:
     * que cada JPEG este, que se pueda leer y que diga sus medidas.
     */
    public function test_la_fototeca_de_la_demo_esta_entera_y_da_medidas(): void
    {
        $fototeca = new SnkKilkanaDemoMediaLibrary;

        $this->assertSame(60, $fototeca->size(), 'faltan fotos en database/seeders/data/media');

        foreach (glob(__DIR__.'/../../packages/Kilkana/database/seeders/data/media/*.jpg') ?: [] as $fichero) {
            $medidas = @getimagesize($fichero);
            $nombre = basename($fichero);

            $this->assertNotFalse($medidas, "«{$nombre}» no se lee como imagen");
            $this->assertGreaterThan(0, $medidas[0], "«{$nombre}» no declara ancho");
            $this->assertGreaterThan(0, $medidas[1], "«{$nombre}» no declara alto");
            $this->assertSame('image/jpeg', $medidas['mime'], "«{$nombre}» no es un JPEG");
        }
    }

    /**
     * Cada portada del snapshot cae en un fichero que existe, y siempre en el mismo.
     *
     * El reparto es `crc32` de la direccion original: dos piezas que compartian foto la siguen
     * compartiendo, y una re-siembra no renumera la biblioteca. Lo que esto impide es lo unico
     * que puede salir mal —una direccion que no encuentre fichero— y que el reparto deje de ser
     * determinista, que se veria como una mediateca distinta despues de cada `--fresh`.
     */
    public function test_cada_portada_cae_en_una_foto_que_existe_y_siempre_en_la_misma(): void
    {
        $fototeca = new SnkKilkanaDemoMediaLibrary;
        $urls = array_values(array_unique(array_filter(array_column($this->snapshot(), 'image'))));

        $this->assertSame(176, count($urls));

        $usadas = [];
        foreach ($urls as $url) {
            $fichero = $fototeca->fileFor($url);

            $this->assertNotNull($fichero, "«{$url}» no encuentra foto");
            $this->assertFileExists($fichero);
            $this->assertSame($fichero, $fototeca->fileFor($url), 'el reparto no es determinista');
            $usadas[$fichero] = true;
        }

        // Sesenta ficheros para 176 direcciones: cuatro fotos se quedan sin reclamar. Entran en
        // la biblioteca igual (`seedAll`), y son las que dan filas a las dos vistas que existen
        // para encontrar trabajo pendiente: «sin usar» y «Missing alt».
        $this->assertSame(56, count($usadas));
        $this->assertSame(60, $fototeca->size());
    }

    public function test_el_cuerpo_generado_habla_el_vocabulario_del_contrato(): void
    {
        $tipos = SnkKilkanaBlockTypeEnum::values();
        $conMuro = 0;

        foreach ($this->snapshot() as $post) {
            $bloques = SnkKilkanaDemoBodyFactory::blocks($post, 7);

            $this->assertNotEmpty($bloques, "«{$post['slug']}» se quedó sin cuerpo");
            $this->assertSame(
                array_map(fn (int $n) => 'b'.($n + 1), array_keys($bloques)),
                array_column($bloques, 'id'),
                "los ids de «{$post['slug']}» no son correlativos",
            );

            $muros = 0;
            foreach ($bloques as $bloque) {
                $this->assertContains($bloque['type'], $tipos, "tipo fuera del contrato en «{$post['slug']}»");
                $this->assertIsArray($bloque['config']);
                $muros += (int) ($bloque['type'] === 'paywall');
            }

            // Un articulo corta por un solo sitio: dos muros no son dos cortes, son un cuerpo
            // que no se sabe donde acaba de estar abierto.
            $this->assertLessThanOrEqual(1, $muros, "«{$post['slug']}» lleva dos muros");

            if ($post['content_tier'] === 'free') {
                $this->assertSame(0, $muros, "una pieza abierta no lleva muro: «{$post['slug']}»");
            } else {
                $this->assertSame(1, $muros, "«{$post['slug']}» es {$post['content_tier']} y no tiene muro");
                $conMuro++;
            }

            // El muro tiene que dejar algo delante y algo detras, o no corta nada: sin teaser
            // la pieza publica sale vacia, y sin resto no habia nada que cerrar.
            $at = array_search('paywall', array_column($bloques, 'type'), true);
            if ($at !== false) {
                $this->assertGreaterThan(0, $at, "el muro de «{$post['slug']}» no deja teaser");
                $this->assertLessThan(count($bloques) - 1, $at, "el muro de «{$post['slug']}» no cierra nada");
            }
        }

        $this->assertSame(139, $conMuro);
    }

    /** El mismo slug da el mismo cuerpo: dos siembras no pueden dar bases distintas. */
    public function test_el_cuerpo_generado_es_determinista(): void
    {
        $post = $this->snapshot()[0];

        $this->assertSame(
            SnkKilkanaDemoBodyFactory::blocks($post, 7),
            SnkKilkanaDemoBodyFactory::blocks($post, 7),
        );
    }

    /** La misma regla que `src/lib/data/slugify.ts`, sin el corte. */
    private function slugLargo(string $titulo): string
    {
        $sin = preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize(mb_strtolower($titulo), \Normalizer::FORM_D));

        return preg_replace('/\s+/', '-', trim(preg_replace('/[^a-z0-9 -]/', '', $sin)));
    }
}
