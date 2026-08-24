<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Services\SnkKilkanaMediaService;
use Tests\TestCase;

/**
 * La mediateca sobre la conexion de inquilino: que publica y quien la usa.
 *
 * Como el listado editorial, se prueba el servicio y no la ruta HTTP: montar una peticion
 * autenticada contra un inquilino exige un arnes que la suite no tiene. Lo que se fija aqui es
 * lo que puede romperse en silencio — una columna nueva colandose en el JSON, y la lista de
 * «Used in» quedandose corta justo en la relacion por la que una foto no se puede borrar.
 */
class KilkanaMediaLibraryTest extends TestCase
{
    private SnkKilkanaMediaService $media;

    protected function setUp(): void
    {
        parent::setUp();

        DB::setDefaultConnection('tenant');
        $this->artisan('migrate', [
            '--database' => 'tenant',
            '--path' => 'packages/Core/database/migrations',
        ])->run();
        $this->artisan('migrate', [
            '--database' => 'tenant',
            '--path' => 'packages/Kilkana/database/migrations',
        ])->run();

        $this->media = app(SnkKilkanaMediaService::class);
    }

    public function test_la_ficha_no_publica_donde_vive_el_fichero(): void
    {
        $foto = $this->foto();

        $fila = $this->media->serialize($foto)->toArray();

        // Por conjunto y no por orden: el orden del JSON lo pone la tabla —`toArray()` recorre
        // los atributos cargados—, no la lista blanca, que se escribe agrupada para leerse.
        $esperadas = SnkKilkanaMediaService::FILA_DE_LA_BIBLIOTECA;
        $publicadas = array_keys($fila);
        sort($esperadas);
        sort($publicadas);

        $this->assertSame($esperadas, $publicadas, 'la ficha publica claves que la lista blanca no nombra');
        // Los dos con los que se borra un fichero: estan en la fila y no salen de ella.
        $this->assertSame('public', $foto->disk);
        $this->assertArrayNotHasKey('path', $fila);
    }

    /**
     * El encuadre viaja como numero y no como cadena.
     *
     * `decimal` de MariaDB sale del JSON como «0.5000», y al otro lado un `x * ancho` con una
     * cadena concatena en vez de multiplicar. El punto ya lo casteaba; el marco entro despues.
     */
    public function test_el_punto_y_el_marco_viajan_como_numeros(): void
    {
        $foto = $this->foto();
        $this->media->update($foto, ['focal_x' => 0.42, 'crop_w' => 0.5]);

        $fila = $this->media->serialize($foto->fresh())->toArray();

        foreach (['focal_x', 'focal_y', 'crop_x', 'crop_y', 'crop_w', 'crop_h'] as $campo) {
            $this->assertIsFloat($fila[$campo], "«{$campo}» no llega como numero");
        }
        $this->assertSame(0.42, $fila['focal_x']);
        $this->assertSame(0.5, $fila['crop_w']);
    }

    /**
     * «Used in» nombra las TRES formas de estar en uso, no solo la portada.
     *
     * Contar solo portadas ofrecia para borrar justo las fotos publicadas: la imagen social de
     * un articulo y el retrato de una firma salian como libres. Las FK son SET NULL, asi que
     * borrarlas no rompia la base — dejaba al articulo sin imagen social y al periodista sin
     * cara, sin un solo error.
     */
    public function test_used_in_nombra_las_tres_formas_de_estar_en_uso(): void
    {
        $foto = $this->foto();
        $categoria = SnkKilkanaCategoryModel::create([
            'name' => 'Local', 'slug' => 'local', 'status' => 'live', 'position' => 0,
        ]);
        $firma = $this->firma('Marta Beneyto');

        $this->pieza('Portada', $categoria, $firma, ['cover_media_id' => $foto->id]);
        $this->pieza('Social', $categoria, $firma, ['og_image_id' => $foto->id]);
        $firma->update(['avatar_media_id' => $foto->id]);

        $usos = $this->media->uses($foto->fresh());

        $this->assertSame(
            ['coveredPosts', 'socialPosts', 'avatarAuthors'],
            array_column($usos, 'kind'),
        );
        $this->assertSame(['Portada', 'Social', 'Marta Beneyto'], array_column($usos, 'title'));
        $this->assertSame(3, $foto->fresh()->usage_count);
    }

    /**
     * Dos peticiones con el MISMO fichero: la segunda vuelve como duplicada, no como error.
     *
     * El dedup es comprobar-y-luego-insertar, y entre las dos mitades cabe otra peticion con el
     * mismo contenido: las dos ven la biblioteca sin el, las dos escriben, y la segunda choca
     * contra el indice unico de `content_hash`. Salia como un 500, asi que el panel decia
     * «rechazada» de un fichero que SI estaba dentro —y dejaba su copia huerfana en el disco—.
     * Se veia soltando la misma foto dos veces en una tanda, porque el panel sube en paralelo.
     *
     * El entrelazado se fuerza y no se espera: un oyente de `creating` mete la fila rival
     * justo DESPUES de que el servicio haya mirado y justo ANTES de que escriba, que es
     * exactamente el hueco de la carrera. Sin eso, la prueba dependeria de que dos hilos se
     * crucen, que es como se escribe una prueba que pasa siempre y no comprueba nada.
     */
    public function test_el_mismo_fichero_a_la_vez_vuelve_como_duplicado(): void
    {
        Storage::fake('public');
        // PNG y no JPEG: la GD de esta imagen viene sin `imagejpeg`, y el generador de
        // Laravel lanza en vez de devolver un fichero. El formato da igual para lo que se prueba.
        $fichero = UploadedFile::fake()->image('puerto.png', 800, 450);
        $hash = hash_file('sha256', $fichero->getRealPath());
        $rival = null;

        SnkKilkanaMediaModel::creating(function () use ($hash, &$rival) {
            if ($rival !== null) {
                return;
            }
            $rival = DB::table('kilkana_media')->insertGetId([
                'url' => '/storage/media/pruebas/la-que-gano.jpg',
                'url_hash' => sha1('/storage/media/pruebas/la-que-gano.jpg'),
                'content_hash' => $hash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $resultado = $this->media->upload(['file' => $fichero], 1);
        } finally {
            SnkKilkanaMediaModel::flushEventListeners();
        }

        $this->assertTrue($resultado['duplicate'], 'el choque se contó como fallo y no como duplicado');
        $this->assertSame($rival, $resultado['media']->id, 'no devuelve la fila que ganó la carrera');
        // Una sola fila con ese contenido: eso es lo que el indice unico existe para garantizar.
        $this->assertSame(1, SnkKilkanaMediaModel::withTrashed()->where('content_hash', $hash)->count());
        // Y sin dejar su copia en el disco, que es basura que nadie va a reclamar.
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    private function foto(): SnkKilkanaMediaModel
    {
        return SnkKilkanaMediaModel::create([
            'url' => '/storage/media/pruebas/foto.jpg',
            'content_hash' => hash('sha256', 'foto'),
            'original_name' => 'foto.jpg',
            'disk' => 'public',
            'path' => 'media/pruebas/foto.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 1024,
            'width' => 800,
            'height' => 450,
        ]);
    }

    private function firma(string $nombre): SnkKilkanaAuthorModel
    {
        $user = CoreUserModel::create([
            'name' => $nombre,
            'email' => str()->slug($nombre).'@eldiario.example',
            'password' => 'secret1234',
        ]);

        return SnkKilkanaAuthorModel::create(['user_id' => $user->id, 'slug' => str()->slug($nombre)]);
    }

    private function pieza(string $titulo, SnkKilkanaCategoryModel $categoria, SnkKilkanaAuthorModel $firma, array $extra): SnkKilkanaPostModel
    {
        return SnkKilkanaPostModel::create([
            'title' => $titulo,
            'excerpt' => 'Entradilla de prueba.',
            'category_id' => $categoria->id,
            'author_id' => $firma->id,
            'status' => 'draft',
            'created_by' => 1,
            'updated_by' => 1,
            ...$extra,
        ]);
    }
}
