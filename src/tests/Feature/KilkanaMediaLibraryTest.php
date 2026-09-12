<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Resources\SnkKilkanaMediaResource;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaMediaCollectionModel;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Services\SnkKilkanaMediaService;
use Tests\TestCase;

/**
 * La mediateca sobre la conexión de inquilino: qué publica y quién la usa.
 *
 * Como el listado editorial, se prueba el servicio y no la ruta HTTP: montar una petición
 * autenticada contra un inquilino exige un arnés que la suite no tiene. Lo que se fija aquí es
 * lo que puede romperse en silencio — una columna nueva colándose en el JSON, y la lista de
 * «Used in» quedándose corta justo en la relación por la que una foto no se puede borrar.
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

    public function test_the_record_does_not_expose_where_the_file_lives(): void
    {
        $photo = $this->photo();

        $row = json_decode(json_encode(new SnkKilkanaMediaResource($this->media->serialize($photo))), true);

        // Por conjunto y no por orden: el orden del JSON lo pone el Resource, que se escribe
        // agrupado para leerse, y esta lista se escribe agrupada por lo que significa cada cosa.
        $expected = [
            'id', 'url', 'original_name', 'title', 'mime', 'size_bytes', 'width', 'height',
            'duration', 'alt', 'caption', 'credit', 'license', 'rights_status', 'content_hash',
            'focal_x', 'focal_y', 'crop_x', 'crop_y', 'crop_w', 'crop_h', 'frames', 'variants',
            'poster_at', 'poster_media_id', 'created_at', 'deleted_at', 'usage_count',
            'tags', 'collections',
        ];
        $published = array_keys($row);
        sort($expected);
        sort($published);

        $this->assertSame($expected, $published, 'la ficha publica claves que esta lista no nombra');

        // `caption` sale: si la lista blanca de la mediateca lo esconde mientras la de la calle
        // lo publica, el pie de una foto lo ve un lector y no quien la edita. Dos listas para el mismo dato es como se llega a eso.
        //
        // Los que no salen son los que llevan al fichero, y esos no dependen de la puerta.
        $this->assertSame('public', $photo->disk);
        foreach (['disk', 'path', 'url_hash', 'uploaded_by', 'description', 'source_url', 'rights_expires_at'] as $inside) {
            $this->assertArrayNotHasKey($inside, $row, "«{$inside}» no lo pinta ninguna pantalla del panel");
        }
    }

    /**
     * El encuadre viaja como número y no como cadena.
     *
     * `decimal` de MariaDB sale del JSON como «0.5000», y al otro lado un `x * ancho` con una
     * cadena concatena en vez de multiplicar. Se castean el punto y el marco.
     */
    public function test_the_focal_point_and_the_crop_travel_as_numbers(): void
    {
        $photo = $this->photo();
        $this->media->update($photo, ['focal_x' => 0.42, 'crop_w' => 0.5]);

        $row = $this->media->serialize($photo->fresh())->toArray();

        foreach (['focal_x', 'focal_y', 'crop_x', 'crop_y', 'crop_w', 'crop_h'] as $field) {
            $this->assertIsFloat($row[$field], "«{$field}» no llega como número");
        }
        $this->assertSame(0.42, $row['focal_x']);
        $this->assertSame(0.5, $row['crop_w']);
    }

    /**
     * «Used in» nombra las TRES formas de estar en uso, no solo la portada.
     *
     * Contar solo portadas ofrece para borrar justo las fotos publicadas: la imagen social de
     * un artículo y el retrato de una firma salen como libres. Las FK son SET NULL, así que
     * borrarlas no rompe la base — deja al artículo sin imagen social y al periodista sin
     * cara, sin un solo error.
     */
    public function test_used_in_names_the_three_ways_of_being_in_use(): void
    {
        $photo = $this->photo();
        $category = SnkKilkanaCategoryModel::create([
            'name' => 'Local', 'slug' => 'local', 'status' => 'live', 'position' => 0,
        ]);
        $byline = $this->byline('Marta Beneyto');

        $this->makePost('Portada', $category, $byline, ['cover_media_id' => $photo->id]);
        $this->makePost('Social', $category, $byline, ['og_image_id' => $photo->id]);
        $byline->update(['avatar_media_id' => $photo->id]);

        $uses = $this->media->uses($photo->fresh());

        $this->assertSame(
            ['coveredPosts', 'socialPosts', 'avatarAuthors'],
            array_column($uses, 'kind'),
        );
        $this->assertSame(['Portada', 'Social', 'Marta Beneyto'], array_column($uses, 'title'));
        $this->assertSame(3, $photo->fresh()->usage_count);
    }

    /**
     * Dos peticiones con el MISMO fichero: la segunda vuelve como duplicada, no como error.
     *
     * El dedup es comprobar-y-luego-insertar, y entre las dos mitades cabe otra petición con el
     * mismo contenido: las dos ven la biblioteca sin él, las dos escriben, y la segunda choca
     * contra el índice único de `content_hash`. Sin tratar el choque sale un 500, y el panel dice
     * «rechazada» de un fichero que SÍ está dentro —y deja su copia huérfana en el disco—.
     * Pasa al soltar la misma foto dos veces en una tanda, porque el panel sube en paralelo.
     *
     * El entrelazado se fuerza y no se espera: un oyente de `creating` mete la fila rival
     * justo DESPUÉS de que el servicio haya mirado y justo ANTES de que escriba, que es
     * exactamente el hueco de la carrera. Sin eso, la prueba dependería de que dos hilos se
     * crucen, que es como se escribe una prueba que pasa siempre y no comprueba nada.
     */
    public function test_the_same_file_uploaded_concurrently_returns_as_duplicate(): void
    {
        Storage::fake('public');
        // PNG y no JPEG: la GD de esta imagen viene sin `imagejpeg`, y el generador de
        // Laravel lanza en vez de devolver un fichero. El formato da igual para lo que se prueba.
        $file = UploadedFile::fake()->image('puerto.png', 800, 450);
        $hash = hash_file('sha256', $file->getRealPath());
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
            $result = $this->media->upload(['file' => $file], 1);
        } finally {
            SnkKilkanaMediaModel::flushEventListeners();
        }

        $this->assertTrue($result['duplicate'], 'el choque se contó como fallo y no como duplicado');
        $this->assertSame($rival, $result['media']->id, 'no devuelve la fila que ganó la carrera');
        // Una sola fila con ese contenido: eso es lo que el índice único existe para garantizar.
        $this->assertSame(1, SnkKilkanaMediaModel::withTrashed()->where('content_hash', $hash)->count());
        // Y sin dejar su copia en el disco, que es basura que nadie va a reclamar.
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    /**
     * Las pestañas del panel son un filtro más, y su cifra dice cuántos saldrían al pulsarlas
     * DENTRO de lo que ya se está mirando: en una colección de una foto, «Images 1». `facets`
     * sigue contando el archivo entero, que es lo que mide la tira de estadísticas.
     */
    public function test_tab_counts_count_within_the_filter(): void
    {
        $culture = SnkKilkanaMediaCollectionModel::create(['name' => 'Cultura', 'slug' => 'cultura']);
        $this->photo()->collections()->attach($culture->id);
        $this->photo('otra.jpg');

        $all = $this->media->index(Request::create('/media'));
        $this->assertSame(['all' => 2, 'image' => 2, 'video' => 0, 'audio' => 0], $all['scope']);

        $inside = $this->media->index(Request::create('/media', 'GET', ['collection' => 'Cultura']));

        $this->assertSame(['all' => 1, 'image' => 1, 'video' => 0, 'audio' => 0], $inside['scope']);
        $this->assertSame(1, $inside['meta']['total'], 'la lista y las pestañas cuentan lo mismo');
        $this->assertSame(2, $inside['facets']['all'], 'las estadísticas siguen midiendo el archivo entero');

        // Y el propio filtro de tipo no se cuenta a sí mismo: con «Video» puesto, «Images»
        // tiene que seguir diciendo cuántas hay, no cero.
        $video = $this->media->index(Request::create('/media', 'GET', ['kind' => 'video']));
        $this->assertSame(['all' => 2, 'image' => 2, 'video' => 0, 'audio' => 0], $video['scope']);
    }

    /**
     * La mediateca recorta con `?fields=` y **sigue pintándose**: `variants` y `usage_count` no
     * son columnas y salen igual, porque el servicio los cuelga después del recorte y añade por
     * su cuenta las cuatro de fontanería de las que se calculan las derivadas.
     */
    public function test_requesting_fields_trims_the_record_and_keeps_what_renders(): void
    {
        $this->photo();

        $page = $this->media->index(Request::create('/media', 'GET', ['fields' => 'id,url,width,height']));
        $row = json_decode(json_encode($page['data']), true)[0];

        $this->assertEqualsCanonicalizing(
            ['id', 'url', 'width', 'height', 'variants', 'usage_count', 'tags', 'collections'],
            array_keys($row),
        );
        $this->assertSame(0, $row['usage_count']);
    }

    private function photo(string $name = 'foto.jpg'): SnkKilkanaMediaModel
    {
        return SnkKilkanaMediaModel::firstOrCreate(['content_hash' => hash('sha256', $name)], [
            'url' => "/storage/media/pruebas/{$name}",
            'original_name' => $name,
            'disk' => 'public',
            'path' => "media/pruebas/{$name}",
            'mime' => 'image/jpeg',
            'size_bytes' => 1024,
            'width' => 800,
            'height' => 450,
        ]);
    }

    private function byline(string $name): SnkKilkanaAuthorModel
    {
        $user = CoreUserModel::create([
            'name' => $name,
            'email' => str()->slug($name).'@eldiario.example',
            'password' => 'secret1234',
        ]);

        return SnkKilkanaAuthorModel::create(['user_id' => $user->id, 'slug' => str()->slug($name)]);
    }

    private function makePost(string $title, SnkKilkanaCategoryModel $category, SnkKilkanaAuthorModel $byline, array $extra): SnkKilkanaPostModel
    {
        return SnkKilkanaPostModel::create([
            'title' => $title,
            'excerpt' => 'Entradilla de prueba.',
            'category_id' => $category->id,
            'author_id' => $byline->id,
            'status' => 'draft',
            'created_by' => 1,
            'updated_by' => 1,
            ...$extra,
        ]);
    }
}
