<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Packages\Kilkana\Jobs\SnkKilkanaMediaWarmJob;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Services\SnkKilkanaImageService;
use Packages\Kilkana\Services\SnkKilkanaMediaService;
use Tests\TestCase;

/**
 * La fábrica de imágenes: subir deja un máster, cada derivada nace una vez bajo demanda y con la
 * huella vigente, un reencuadre es una huella nueva, y lo que no cabe en memoria se rechaza antes
 * de decodificar.
 */
class KilkanaMediaVariantsTest extends TestCase
{
    private SnkKilkanaMediaService $media;

    private SnkKilkanaImageService $images;

    protected function setUp(): void
    {
        parent::setUp();

        DB::setDefaultConnection('tenant');
        foreach (['Core', 'Kilkana'] as $paquete) {
            $this->artisan('migrate', ['--database' => 'tenant', '--path' => "packages/{$paquete}/database/migrations"])->run();
        }
        Storage::fake('public');
        Queue::fake();
        $this->media = app(SnkKilkanaMediaService::class);
        $this->images = app(SnkKilkanaImageService::class);
    }

    public function test_subir_deja_un_master_acotado_y_una_huella_y_calienta_por_cola(): void
    {
        config(['kilkana.kilkana.media.master' => 800]);
        $subida = $this->media->upload(['file' => UploadedFile::fake()->image('puerto.jpg', 1600, 1000)], 1)['media'];

        $this->assertNotNull($subida->master_path);
        $this->assertSame([800, 500], [$subida->master_width, $subida->master_height], 'El máster se acota al lado mayor configurado.');
        $this->assertTrue(Storage::disk('public')->exists($subida->master_path));
        $this->assertTrue(Storage::disk('public')->exists($subida->path), 'Y el original se conserva.');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $subida->variants_key);
        $this->assertSame([160, 320, 640], $subida->variants['widths'], 'Solo los anchos que caben en el máster.');
        $this->assertSame(['width' => 800, 'height' => 450], $subida->variants['ratios']['hero']);
        Queue::assertPushed(SnkKilkanaMediaWarmJob::class, fn ($job) => $job->mediaId === $subida->id);
    }

    public function test_una_derivada_nace_bajo_demanda_con_la_huella_vigente_y_solo_de_la_lista(): void
    {
        config(['kilkana.kilkana.media.master' => 800]);
        $media = SnkKilkanaMediaModel::find($this->media->upload(['file' => UploadedFile::fake()->image('plaza.png', 1200, 800)], 1)['media']->id);

        $path = $this->images->variant($media, 'square', 320, 'webp');
        $this->assertSame(SnkKilkanaImageService::variantDir($media).'/square-320.webp', $path);
        $this->assertTrue(Storage::disk('public')->exists($path));
        [$w, $h] = getimagesize(Storage::disk('public')->path($path));
        $this->assertSame([320, 320], [$w, $h]);

        $this->assertNull($this->images->variant($media, 'panorama', 320, 'webp'), 'Proporción fuera de la lista.');
        $this->assertNull($this->images->variant($media, 'square', 333, 'webp'), 'Ancho fuera de la lista.');
        $this->assertNull($this->images->variant($media, 'square', 320, 'gif'), 'Formato fuera de la lista.');

        // Un ancho mayor que la ventana no amplía: la derivada sale del tamaño de la ventana.
        $grande = $this->images->variant($media, 'og', 1920, 'jpeg');
        $this->assertNull($grande, 'Y un ancho que el máster no da tampoco existe.');
    }

    public function test_reencuadrar_cambia_la_huella_y_la_purga_barre_la_vieja(): void
    {
        config(['kilkana.kilkana.media.master' => 800]);
        $media = SnkKilkanaMediaModel::find($this->media->upload(['file' => UploadedFile::fake()->image('retrato.jpg', 900, 1200)], 1)['media']->id);
        $vieja = $media->variants_key;
        $antes = $this->images->variant($media, 'card', 320, 'jpeg');

        $this->media->update($media, ['focal_x' => 0.2, 'focal_y' => 0.1]);
        $media->refresh();
        $this->assertNotSame($vieja, $media->variants_key);
        $this->media->update($media, ['alt' => 'La plaza']);
        $this->assertSame($media->fresh()->variants_key, $media->variants_key, 'Un cambio de alt no toca la huella.');

        $this->assertTrue(Storage::disk('public')->exists($antes));
        $this->assertSame(1, $this->images->purgeOrphans());
        $this->assertFalse(Storage::disk('public')->exists($antes));
    }

    public function test_lo_que_no_cabe_en_memoria_se_rechaza_por_la_cabecera_con_mensaje(): void
    {
        config(['kilkana.kilkana.media.max_megapixels' => 1]);
        try {
            $this->media->upload(['file' => UploadedFile::fake()->image('enorme.jpg', 2000, 1000)], 1);
            $this->fail('Dos megapíxeles con tope de uno tienen que rebotar.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('megapixels', $e->errors()['file'][0]);
        }
        $this->assertSame(0, SnkKilkanaMediaModel::query()->count(), 'Y no queda fila a medias.');
    }

    public function test_el_avif_es_el_primer_formato_y_nace_bajo_demanda(): void
    {
        config(['kilkana.kilkana.media.master' => 800]);
        $media = SnkKilkanaMediaModel::find($this->media->upload(['file' => UploadedFile::fake()->image('plaza.png', 1200, 800)], 1)['media']->id);

        $this->assertSame(['avif', 'webp', 'jpeg'], $media->variants['formats'], 'GD viene con libavif en la imagen.');
        $path = $this->images->variant($media, 'card', 320, 'avif');
        $this->assertNotNull($path);
        $this->assertSame('image/avif', mime_content_type(Storage::disk('public')->path($path)));
        $this->assertSame([], glob(Storage::disk('public')->path(SnkKilkanaImageService::variantDir($media)).'/*.tmp'), 'Nada a medio escribir se queda en disco.');
    }

    /** Un vídeo se mide con `ffprobe` y gana un fotograma de portada, que es su máster: derivadas como una foto. */
    public function test_un_video_trae_su_duracion_y_un_fotograma_como_master(): void
    {
        config(['kilkana.kilkana.media.master' => 800]);
        $tmp = tempnam(sys_get_temp_dir(), 'kk-video-').'.mp4';
        shell_exec(sprintf('ffmpeg -v error -y -f lavfi -i testsrc=duration=3:size=320x240:rate=10 -pix_fmt yuv420p %s', escapeshellarg($tmp)));
        $this->assertGreaterThan(0, filesize($tmp), 'ffmpeg está en la imagen y sabe fabricar un vídeo.');

        $subida = $this->media->upload(['file' => new UploadedFile($tmp, 'prueba.mp4', 'video/mp4', null, true)], 1)['media'];

        $this->assertSame(3, $subida->duration, 'La duración la dice el fichero.');
        $this->assertSame([320, 240], [$subida->width, $subida->height]);
        $this->assertNotNull($subida->master_path, 'El fotograma es el máster.');
        $this->assertSame([320, 240], [$subida->master_width, $subida->master_height]);
        $this->assertNotNull($subida->variants);
        $this->assertNotNull($this->images->variant(SnkKilkanaMediaModel::find($subida->id), 'card', 320, 'jpeg'));
        $this->assertSame(1, $subida->poster_at, 'La portada por defecto es el segundo uno.');

        // Elegir otro segundo: miniatura para verlo, y la portada nueva cambia máster y huella.
        $video = SnkKilkanaMediaModel::find($subida->id);
        $this->assertNotNull($this->images->frame($video, 2));
        $this->assertNull($this->images->frame($video, 9), 'Más allá de la duración no hay fotograma.');
        $antes = [$video->master_path, $video->variants_key];
        $this->assertNotNull($this->media->poster($video, 2));
        $video->refresh();
        $this->assertSame(2, $video->poster_at);
        $this->assertNotSame($antes[0], $video->master_path);
        $this->assertNotSame($antes[1], $video->variants_key);
        $this->assertFalse(Storage::disk('public')->exists($antes[0]), 'El máster viejo se va.');

        // Una foto de la biblioteca como portada: el máster del vídeo pasa a ser una copia del suyo.
        $foto = SnkKilkanaMediaModel::find($this->media->upload(['file' => UploadedFile::fake()->image('plaza.jpg', 1600, 1000)], 1)['media']->id);
        $this->assertNotNull($this->media->posterFrom($video->fresh(), $foto));
        $video->refresh();
        $this->assertSame($foto->id, $video->poster_media_id);
        $this->assertNull($video->poster_at);
        $this->assertSame([$foto->master_width, $foto->master_height], [$video->master_width, $video->master_height]);
        $this->assertNotSame($foto->master_path, $video->master_path, 'Copia, no referencia.');
        $this->assertTrue(Storage::disk('public')->exists($video->master_path));

        // La purga barre los fotogramas de elegir que no son el vigente, y todos los de un vídeo que ya no está.
        $this->assertNotNull($this->images->frame($video, 0));
        $this->assertNotNull($this->images->frame($video, 2));
        $this->assertGreaterThanOrEqual(2, $this->images->purgeOrphans(), 'Sin poster_at, ningún fotograma es el vigente.');
        $this->assertSame([], Storage::disk('public')->files(sprintf('media/%s/f/%d', ':memory:', $video->id)));
        @unlink($tmp);
    }
}
