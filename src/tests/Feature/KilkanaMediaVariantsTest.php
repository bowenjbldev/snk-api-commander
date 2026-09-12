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
        foreach (['Core', 'Kilkana'] as $package) {
            $this->artisan('migrate', ['--database' => 'tenant', '--path' => "packages/{$package}/database/migrations"])->run();
        }
        Storage::fake('public');
        Queue::fake();
        $this->media = app(SnkKilkanaMediaService::class);
        $this->images = app(SnkKilkanaImageService::class);
    }

    public function test_upload_leaves_a_bounded_master_and_a_fingerprint_and_warms_via_the_queue(): void
    {
        config(['kilkana.kilkana.media.master' => 800]);
        $uploaded = $this->media->upload(['file' => UploadedFile::fake()->image('puerto.jpg', 1600, 1000)], 1)['media'];

        $this->assertNotNull($uploaded->master_path);
        $this->assertSame([800, 500], [$uploaded->master_width, $uploaded->master_height], 'El máster se acota al lado mayor configurado.');
        $this->assertTrue(Storage::disk('public')->exists($uploaded->master_path));
        $this->assertTrue(Storage::disk('public')->exists($uploaded->path), 'Y el original se conserva.');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $uploaded->variants_key);
        $this->assertSame([160, 320, 640], $uploaded->variants['widths'], 'Solo los anchos que caben en el máster.');
        $this->assertSame(['width' => 800, 'height' => 450], $uploaded->variants['ratios']['hero']);
        Queue::assertPushed(SnkKilkanaMediaWarmJob::class, fn ($job) => $job->mediaId === $uploaded->id);
    }

    public function test_a_variant_is_built_on_demand_with_the_current_fingerprint_and_only_from_the_list(): void
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
        $large = $this->images->variant($media, 'og', 1920, 'jpeg');
        $this->assertNull($large, 'Y un ancho que el máster no da tampoco existe.');
    }

    public function test_recropping_changes_the_fingerprint_and_the_purge_sweeps_the_old_one(): void
    {
        config(['kilkana.kilkana.media.master' => 800]);
        $media = SnkKilkanaMediaModel::find($this->media->upload(['file' => UploadedFile::fake()->image('retrato.jpg', 900, 1200)], 1)['media']->id);
        $oldKey = $media->variants_key;
        $before = $this->images->variant($media, 'card', 320, 'jpeg');

        $this->media->update($media, ['focal_x' => 0.2, 'focal_y' => 0.1]);
        $media->refresh();
        $this->assertNotSame($oldKey, $media->variants_key);
        $this->media->update($media, ['alt' => 'La plaza']);
        $this->assertSame($media->fresh()->variants_key, $media->variants_key, 'Un cambio de alt no toca la huella.');

        $this->assertTrue(Storage::disk('public')->exists($before));
        $this->assertSame(1, $this->images->purgeOrphans());
        $this->assertFalse(Storage::disk('public')->exists($before));
    }

    /**
     * Un encuadre propio manda sobre el punto en SU proporción y en ninguna otra, entra en la
     * huella, y una proporción que la fábrica no produce no se guarda.
     */
    public function test_a_custom_crop_moves_only_its_ratio_and_changes_the_fingerprint(): void
    {
        config(['kilkana.kilkana.media.master' => 800]);
        $media = SnkKilkanaMediaModel::find($this->media->upload(['file' => UploadedFile::fake()->image('plaza.jpg', 1600, 1000)], 1)['media']->id);
        $before = $media->variants_key;
        $cardBefore = $media->variants['ratios']['card'];

        $this->media->update($media, ['frames' => ['portrait' => ['x' => 0.7, 'y' => 0.0, 'w' => 0.5, 'h' => 1.0], 'panorama' => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]]]);
        $media->refresh();

        $this->assertSame(['portrait'], array_keys($media->frames), 'Solo las proporciones de la fábrica.');
        $this->assertNotSame($before, $media->variants_key, 'El encuadre propio entra en la huella.');
        $this->assertSame($cardBefore, $media->variants['ratios']['card'], 'Las demás proporciones no se mueven.');

        // 800 × 500 de máster: la caja pide la mitad del ancho (400) y su alto 2:3 (600) no cabe,
        // así que se acota al alto entero y el ancho baja a 333; y empieza donde la imagen acaba.
        $win = SnkKilkanaImageService::window(800, 500, ['x' => 0.5, 'y' => 0.5], ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1], [2, 3], $media->frames['portrait']);
        $this->assertSame(['x' => 467, 'y' => 0, 'w' => 333, 'h' => 500], $win);
        $this->assertSame(['width' => 333, 'height' => 500], $media->variants['ratios']['portrait']);

        // Y el recorte acota también al encuadre propio: con el marco en la mitad izquierda, la
        // ventana entra en él aunque se guardara pegada al borde derecho.
        $scoped = SnkKilkanaImageService::window(800, 500, ['x' => 0.5, 'y' => 0.5], ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 1], [2, 3], $media->frames['portrait']);
        $this->assertSame(['x' => 67, 'y' => 0, 'w' => 333, 'h' => 500], $scoped);

        $this->media->update($media, ['frames' => []]);
        $this->assertNull($media->fresh()->frames, 'Vaciar los encuadres propios deja nulo.');
    }

    public function test_what_does_not_fit_in_memory_is_rejected_from_the_header_with_a_message(): void
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

    public function test_avif_is_the_first_format_and_is_built_on_demand(): void
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
    public function test_a_video_includes_its_duration_and_a_frame_as_master(): void
    {
        config(['kilkana.kilkana.media.master' => 800]);
        $tmp = tempnam(sys_get_temp_dir(), 'kk-video-').'.mp4';
        shell_exec(sprintf('ffmpeg -v error -y -f lavfi -i testsrc=duration=3:size=320x240:rate=10 -pix_fmt yuv420p %s', escapeshellarg($tmp)));
        $this->assertGreaterThan(0, filesize($tmp), 'ffmpeg está en la imagen y sabe fabricar un vídeo.');

        $uploaded = $this->media->upload(['file' => new UploadedFile($tmp, 'prueba.mp4', 'video/mp4', null, true)], 1)['media'];

        $this->assertSame(3, $uploaded->duration, 'La duración la dice el fichero.');
        $this->assertSame([320, 240], [$uploaded->width, $uploaded->height]);
        $this->assertNotNull($uploaded->master_path, 'El fotograma es el máster.');
        $this->assertSame([320, 240], [$uploaded->master_width, $uploaded->master_height]);
        $this->assertNotNull($uploaded->variants);
        $this->assertNotNull($this->images->variant(SnkKilkanaMediaModel::find($uploaded->id), 'card', 320, 'jpeg'));
        $this->assertSame(1, $uploaded->poster_at, 'La portada por defecto es el segundo uno.');

        // Elegir otro segundo: miniatura para verlo, y la portada nueva cambia máster y huella.
        $video = SnkKilkanaMediaModel::find($uploaded->id);
        $this->assertNotNull($this->images->frame($video, 2));
        $this->assertNull($this->images->frame($video, 9), 'Más allá de la duración no hay fotograma.');
        $before = [$video->master_path, $video->variants_key];
        $this->assertNotNull($this->media->poster($video, 2));
        $video->refresh();
        $this->assertSame(2, $video->poster_at);
        $this->assertNotSame($before[0], $video->master_path);
        $this->assertNotSame($before[1], $video->variants_key);
        $this->assertFalse(Storage::disk('public')->exists($before[0]), 'El máster viejo se va.');

        // Una foto de la biblioteca como portada: el máster del vídeo pasa a ser una copia del suyo.
        $photo = SnkKilkanaMediaModel::find($this->media->upload(['file' => UploadedFile::fake()->image('plaza.jpg', 1600, 1000)], 1)['media']->id);
        $this->assertNotNull($this->media->posterFrom($video->fresh(), $photo));
        $video->refresh();
        $this->assertSame($photo->id, $video->poster_media_id);
        $this->assertNull($video->poster_at);
        $this->assertSame([$photo->master_width, $photo->master_height], [$video->master_width, $video->master_height]);
        $this->assertNotSame($photo->master_path, $video->master_path, 'Copia, no referencia.');
        $this->assertTrue(Storage::disk('public')->exists($video->master_path));

        // La purga barre los fotogramas de elegir que no son el vigente, y todos los de un vídeo que ya no está.
        $this->assertNotNull($this->images->frame($video, 0));
        $this->assertNotNull($this->images->frame($video, 2));
        $this->assertGreaterThanOrEqual(2, $this->images->purgeOrphans(), 'Sin poster_at, ningún fotograma es el vigente.');
        $this->assertSame([], Storage::disk('public')->files(sprintf('media/%s/f/%d', ':memory:', $video->id)));
        @unlink($tmp);
    }
}
