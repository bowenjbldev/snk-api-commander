<?php

namespace Packages\Kilkana\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;

/**
 * La fábrica de imágenes: de cada foto, un máster y las derivadas que la calle pide.
 *
 * El máster se hace una vez al subir —orientación EXIF aplicada, metadatos fuera, lado mayor
 * acotado— y es el origen de todo lo demás; el original se conserva en disco y nunca se sirve.
 * Una derivada es una proporción, un ancho y un formato, y se genera la primera vez que alguien
 * la pide: su ruta lleva una huella del contenido y del encuadre, así que un reencuadre es una
 * ruta nueva y las viejas se barren. El recorte se calcula siempre igual: primero el rectángulo
 * que la redacción guardó, y dentro de él la ventana de la proporción centrada en el foco.
 */
class SnkKilkanaImageService
{
    /** Las proporciones que un tema declara para sus huecos, más la imagen social. */
    public const RATIOS = [
        'hero' => [16, 9],
        'card' => [3, 2],
        'thumb' => [4, 3],
        'portrait' => [2, 3],
        'square' => [1, 1],
        'og' => [1200, 630],
        'original' => null,
    ];

    /** Los tres formatos, de mejor a peor compresión; `avif` solo si GD lo trae (`imageavif`). */
    public const FORMATS = ['avif', 'webp', 'jpeg'];

    /** Lo que la fábrica sabe abrir; lo demás se guarda y se sirve tal cual. */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function isImage(?string $mime): bool
    {
        return in_array((string) $mime, self::IMAGE_MIMES, true);
    }

    /** Vídeo o audio: lo que `ffprobe` sabe leer. */
    public function isMedia(?string $mime): bool
    {
        return str_starts_with((string) $mime, 'video/') || str_starts_with((string) $mime, 'audio/');
    }

    /** Los formatos que esta instalación produce de verdad: `avif` pide un GD compilado con libavif. */
    public static function formats(): array
    {
        return array_values(array_filter(self::FORMATS, fn (string $f) => $f !== 'avif' || function_exists('imageavif')));
    }

    /**
     * Escribe entero o no escribe: al nombre final se llega por `rename()`, que en el mismo
     * sistema de ficheros es atómico. nginx mira el fichero antes que a Laravel (`try_files`),
     * y un fichero a medio escribir servido con `immutable` se queda roto un año en quien lo vio.
     */
    private static function putAtomic(string $path, string $contents): void
    {
        $disk = Storage::disk('public');
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        $disk->put($tmp, $contents);
        if (! @rename($disk->path($tmp), $disk->path($path))) {
            $disk->delete($tmp);
            throw new \RuntimeException("Could not move {$tmp} into place.");
        }
    }

    /**
     * Lo que `ffprobe` dice de un vídeo o un audio: duración en segundos y, en vídeo, medidas.
     * Sin `ffprobe` en la imagen devuelve `null` y se queda lo que declaró quien subió.
     */
    public function probe(string $realPath): ?array
    {
        // `-protocol_whitelist file` cierra la red: el contenido es de quien sube, y un contenedor
        // que referencie `http://` o `tcp://` desde dentro saldría a buscarlo (la metadata de la
        // nube, un servicio interno) y lo metería en el fotograma. Lo local ya lo corta el propio
        // ffmpeg de esta compilación por extensión de segmento; la lista blanca es nuestra y no
        // depende de la versión. `timeout`: un proceso hijo no obedece a `max_execution_time`, y
        // un fichero preparado no puede clavar un worker de php-fpm sin límite.
        $cmd = sprintf(
            'timeout 10 ffprobe -v error -protocol_whitelist file -show_entries format=duration -show_entries stream=width,height,codec_type -of json %s 2>/dev/null',
            escapeshellarg($realPath),
        );
        $out = @shell_exec($cmd);
        if (! is_string($out) || $out === '') {
            return null;
        }
        $json = json_decode($out, true);
        if (! is_array($json)) {
            return null;
        }
        $video = collect($json['streams'] ?? [])->first(fn ($s) => ($s['codec_type'] ?? null) === 'video');
        $duration = isset($json['format']['duration']) ? (int) round((float) $json['format']['duration']) : null;

        return [
            'duration' => $duration,
            'width' => isset($video['width']) ? (int) $video['width'] : null,
            'height' => isset($video['height']) ? (int) $video['height'] : null,
        ];
    }

    /** El segundo por defecto para la portada de un vídeo: el uno, o el cero si dura menos. */
    public static function defaultPosterAt(?int $duration): int
    {
        return ($duration ?? 0) > 1 ? 1 : 0;
    }

    /**
     * El fotograma de portada de un vídeo, al segundo pedido, como máster: con él un vídeo
     * tiene derivadas igual que una foto. `null` si `ffmpeg` no lo saca. El máster lleva el
     * segundo en el nombre, así que elegir otro es otro fichero y otra huella.
     */
    public function poster(string $realPath, string $dir, string $stem, int $at): ?array
    {
        $frame = $this->grab($realPath, $at, null);
        if ($frame === null) {
            return null;
        }
        try {
            return $this->master($frame, $dir, "{$stem}-p{$at}");
        } finally {
            @unlink($frame);
        }
    }

    /**
     * Una miniatura de un segundo concreto, para elegir la portada: pequeña, cacheada por
     * medio y segundo en el disco público, y generada la primera vez. Devuelve la ruta.
     */
    public function frame(SnkKilkanaMediaModel $media, int $at): ?string
    {
        $disk = Storage::disk('public');
        if (! $media->path || $at < 0 || $at > max(0, (int) $media->duration)) {
            return null;
        }
        $path = sprintf('media/%s/f/%d/%d.jpg', DB::connection()->getDatabaseName(), $media->getKey(), $at);
        if ($disk->exists($path)) {
            return $path;
        }
        $lock = Cache::lock('kilkana:frame:'.$path, 30);
        try {
            $lock->block(20);
        } catch (LockTimeoutException) {
            // Se sigue sin candado.
        }
        try {
            if ($disk->exists($path)) {
                return $path;
            }
            $frame = $this->grab($disk->path($media->path), $at, 320);
            if ($frame === null) {
                return null;
            }
            try {
                self::putAtomic($path, (string) file_get_contents($frame));
            } finally {
                @unlink($frame);
            }

            return $path;
        } finally {
            $lock->release();
        }
    }

    /**
     * Un fotograma con ffmpeg a un fichero temporal, o `null`. `tempnam` crea el fichero que
     * devuelve: se usa esa ruta y no otra con sufijo, o queda un cero bytes en `/tmp` por
     * vídeo para siempre; el formato se le dice con `-f image2`. Con lista blanca de
     * protocolos (cierra la red) y `timeout` (un hijo no obedece a `max_execution_time`).
     */
    private function grab(string $realPath, int $at, ?int $width): ?string
    {
        $frame = tempnam(sys_get_temp_dir(), 'kk-poster-');
        if ($frame === false) {
            return null;
        }
        $cmd = sprintf(
            'timeout 20 ffmpeg -v error -y -protocol_whitelist file -ss %d -i %s -frames:v 1 -q:v 3 %s -f image2 %s 2>/dev/null',
            $at,
            escapeshellarg($realPath),
            $width ? '-vf '.escapeshellarg("scale={$width}:-2") : '',
            escapeshellarg($frame),
        );
        @shell_exec($cmd);
        if (! is_file($frame) || filesize($frame) === 0) {
            @unlink($frame);

            return null;
        }

        return $frame;
    }

    /**
     * Lo que dice la cabecera del fichero, sin decodificarlo: tipo real y medidas.
     * Rechaza lo que no cabe en memoria antes de que sea un error fatal.
     */
    public function inspect(string $realPath): ?array
    {
        $size = @getimagesize($realPath);
        if (! $size) {
            return null;
        }
        [$width, $height] = $size;
        $megapixels = ($width * $height) / 1_000_000;
        $tope = (int) config('kilkana.kilkana.media.max_megapixels', 20);
        if ($megapixels > $tope) {
            throw ValidationException::withMessages([
                'file' => sprintf('That image is %.0f megapixels; the limit is %d. Export a smaller copy and upload that.', $megapixels, $tope),
            ]);
        }

        return ['width' => $width, 'height' => $height, 'mime' => (string) ($size['mime'] ?? '')];
    }

    /**
     * El máster: orientado, sin metadatos, acotado al lado mayor configurado, guardado como JPEG
     * junto al original. Devuelve ruta y medidas, o `null` si el fichero no es una imagen.
     */
    public function master(string $realPath, string $dir, string $stem): ?array
    {
        $manager = new ImageManager(new Driver);
        try {
            $image = $manager->read($realPath);
        } catch (\Throwable) {
            return null;
        }
        // `read()` ya aplica la orientación EXIF; reescalar y reencodificar deja fuera el resto
        // de metadatos —la geolocalización de una foto de móvil, entre ellos—.
        $lado = (int) config('kilkana.kilkana.media.master', 2560);
        $image->scaleDown($lado, $lado);
        $path = "{$dir}/m/{$stem}.jpg";
        self::putAtomic($path, (string) $image->toJpeg((int) config('kilkana.kilkana.media.quality.master', 90)));

        return ['path' => $path, 'width' => $image->width(), 'height' => $image->height()];
    }

    /** La huella de las derivadas: cambia con el contenido y con el encuadre, y con nada más. */
    public static function fingerprint(SnkKilkanaMediaModel $media): string
    {
        return substr(sha1(implode('|', [
            (string) $media->content_hash,
            number_format((float) $media->focal_x, 4, '.', ''),
            number_format((float) $media->focal_y, 4, '.', ''),
            number_format((float) $media->crop_x, 4, '.', ''),
            number_format((float) $media->crop_y, 4, '.', ''),
            number_format((float) $media->crop_w, 4, '.', ''),
            number_format((float) $media->crop_h, 4, '.', ''),
            (string) ($media->poster_at ?? ''),
            (string) ($media->poster_media_id ?? ''),
        ])), 0, 8);
    }

    /**
     * La ventana de una proporción sobre una imagen, en píxeles: primero el rectángulo guardado
     * por la redacción, y dentro de él la caja más grande con esa proporción, centrada en el
     * punto focal y empujada hasta caber. Sin proporción, el rectángulo guardado entero.
     *
     * @return array{x:int,y:int,w:int,h:int}
     */
    public static function window(int $width, int $height, array $focal, array $crop, ?array $ratio): array
    {
        $cx = (int) round($crop['x'] * $width);
        $cy = (int) round($crop['y'] * $height);
        $cw = max(1, (int) round($crop['w'] * $width));
        $ch = max(1, (int) round($crop['h'] * $height));
        $cw = min($cw, $width - $cx);
        $ch = min($ch, $height - $cy);
        if ($ratio === null) {
            return ['x' => $cx, 'y' => $cy, 'w' => $cw, 'h' => $ch];
        }

        $target = $ratio[0] / $ratio[1];
        if ($cw / $ch > $target) {
            $w = max(1, (int) round($ch * $target));
            $h = $ch;
        } else {
            $w = $cw;
            $h = max(1, (int) round($cw / $target));
        }
        // El foco en píxeles de la imagen entera; la ventana se centra en él y se empuja para
        // no salirse del rectángulo guardado.
        $fx = $focal['x'] * $width;
        $fy = $focal['y'] * $height;
        $x = (int) round($fx - $w / 2);
        $y = (int) round($fy - $h / 2);
        $x = max($cx, min($x, $cx + $cw - $w));
        $y = max($cy, min($y, $cy + $ch - $h));

        return ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
    }

    /** La carpeta de las derivadas vigentes de un medio, relativa al disco `public`. */
    public static function variantDir(SnkKilkanaMediaModel $media): string
    {
        return sprintf('media/%s/v/%d/%s', DB::connection()->getDatabaseName(), $media->getKey(), $media->variants_key);
    }

    /**
     * Lo que la calle y el panel necesitan para pedir una derivada sin calcular nada: la base de
     * la URL, los anchos que existen y, por proporción, las medidas que tendrá al ancho máximo.
     */
    public static function payload(SnkKilkanaMediaModel $media): ?array
    {
        if (! $media->master_path || ! $media->variants_key || ! $media->master_width || ! $media->master_height) {
            return null;
        }
        $focal = ['x' => (float) $media->focal_x, 'y' => (float) $media->focal_y];
        $crop = ['x' => (float) $media->crop_x, 'y' => (float) $media->crop_y, 'w' => (float) $media->crop_w, 'h' => (float) $media->crop_h];
        $ratios = [];
        foreach (self::RATIOS as $name => $ratio) {
            $win = self::window((int) $media->master_width, (int) $media->master_height, $focal, $crop, $ratio);
            $ratios[$name] = ['width' => $win['w'], 'height' => $win['h']];
        }
        $widths = array_values(array_filter(
            (array) config('kilkana.kilkana.media.widths', [160, 320, 640, 960, 1280, 1920]),
            fn (int $w) => $w <= (int) $media->master_width,
        ));
        if ($widths === []) {
            $widths = [(int) $media->master_width];
        }

        return [
            'base' => '/storage/'.self::variantDir($media),
            'widths' => $widths,
            'formats' => self::formats(),
            'ratios' => $ratios,
        ];
    }

    /**
     * Una derivada, generada si no existe. Devuelve la ruta en el disco `public`, o `null` si
     * la petición no encaja con lo que la fábrica produce: proporción, ancho o formato fuera de
     * la lista, o un medio sin máster.
     */
    public function variant(SnkKilkanaMediaModel $media, string $ratio, int $width, string $format): ?string
    {
        if (! array_key_exists($ratio, self::RATIOS) || ! in_array($format, self::formats(), true)) {
            return null;
        }
        if (! in_array($width, (array) config('kilkana.kilkana.media.widths'), true) || ! $media->master_path || ! $media->variants_key) {
            return null;
        }
        // Lo mismo que `payload()` anuncia: un ancho que el máster no da no existe, ni ampliado.
        if ($width > (int) $media->master_width) {
            return null;
        }
        $path = self::variantDir($media)."/{$ratio}-{$width}.{$format}";
        $disk = Storage::disk('public');
        if ($disk->exists($path)) {
            return $path;
        }

        // Un candado por derivada: veinte peticiones de la misma a la vez son una decodificación
        // del máster, no veinte —el rebaño de generar al vuelo—; las demás esperan y se sirven
        // del fichero que la primera dejó. Si el candado no llega, se genera igual: mejor un
        // trabajo repetido que una foto que no sale.
        $lock = Cache::lock('kilkana:variant:'.$path, 30);
        try {
            $lock->block(20);
        } catch (LockTimeoutException) {
            // Se sigue sin candado.
        }
        try {
            if ($disk->exists($path)) {
                return $path;
            }

            return $this->render($media, $ratio, $width, $format, $path);
        } finally {
            $lock->release();
        }
    }

    private function render(SnkKilkanaMediaModel $media, string $ratio, int $width, string $format, string $path): string
    {
        $disk = Storage::disk('public');
        $focal = ['x' => (float) $media->focal_x, 'y' => (float) $media->focal_y];
        $crop = ['x' => (float) $media->crop_x, 'y' => (float) $media->crop_y, 'w' => (float) $media->crop_w, 'h' => (float) $media->crop_h];
        $win = self::window((int) $media->master_width, (int) $media->master_height, $focal, $crop, self::RATIOS[$ratio]);

        $image = (new ImageManager(new Driver))->read($disk->path($media->master_path));
        $image->crop($win['w'], $win['h'], $win['x'], $win['y']);
        // Nunca por encima de lo que la ventana da: ampliar inventa píxeles.
        if ($width < $win['w']) {
            $image->scaleDown(width: $width);
        }
        $quality = (int) config("kilkana.kilkana.media.quality.{$format}", 80);
        $encoded = match ($format) {
            'avif' => $image->toAvif($quality),
            'webp' => $image->toWebp($quality),
            default => $image->toJpeg($quality),
        };
        self::putAtomic($path, (string) $encoded);

        return $path;
    }

    /**
     * Barre lo que ya no apunta nadie: las carpetas de derivadas cuya huella no es la vigente
     * de su medio, y los fotogramas de elegir portada —todos los de un vídeo que ya no existe,
     * y los que no son el segundo elegido de uno que sí, porque se rehacen al tocar el
     * deslizador y sin barrerlos un pleno de noventa minutos son cinco mil ficheros—.
     */
    public function purgeOrphans(): int
    {
        $disk = Storage::disk('public');
        $base = 'media/'.DB::connection()->getDatabaseName();
        $barridas = 0;
        foreach ($disk->directories($base.'/v') as $carpeta) {
            $id = (int) basename($carpeta);
            $vigente = SnkKilkanaMediaModel::withTrashed()->whereKey($id)->value('variants_key');
            foreach ($disk->directories($carpeta) as $huella) {
                if ($vigente === null || basename($huella) !== $vigente) {
                    $disk->deleteDirectory($huella);
                    $barridas++;
                }
            }
        }
        foreach ($disk->directories($base.'/f') as $carpeta) {
            $id = (int) basename($carpeta);
            $video = SnkKilkanaMediaModel::withTrashed()->whereKey($id)->first(['id', 'poster_at']);
            if ($video === null) {
                $disk->deleteDirectory($carpeta);
                $barridas++;

                continue;
            }
            foreach ($disk->files($carpeta) as $fotograma) {
                // Sin segundo elegido —la portada es una foto— no hay fotograma vigente que guardar.
                if ($video->poster_at === null || (int) pathinfo($fotograma, PATHINFO_FILENAME) !== (int) $video->poster_at) {
                    $disk->delete($fotograma);
                    $barridas++;
                }
            }
        }

        return $barridas;
    }
}
