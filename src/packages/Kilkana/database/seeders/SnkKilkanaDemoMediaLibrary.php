<?php

namespace Packages\Kilkana\Database\Seeders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Packages\Kilkana\Enums\SnkKilkanaMediaRightsEnum;
use Packages\Kilkana\Jobs\SnkKilkanaMediaWarmJob;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Services\SnkKilkanaImageService;

/**
 * La fototeca de la demo, servida desde el disco del periódico y no desde un tercero.
 *
 * El snapshot trae en cada pieza una dirección de `loremflickr`, que es un servicio de fotos
 * de relleno. Una fila con esa dirección y nada más no es un fichero: no tiene ancho, ni alto,
 * ni peso, ni `content_hash`, así que la mediateca no puede deduplicar, ni filtrar por
 * orientación, ni decir cuánto ocupa; y el lector paga DNS, TLS y una cookie de tercero por
 * cada foto, y eso deja la nota de buenas prácticas del artículo en 96.
 *
 * Aquí las sesenta fotos de `data/media/` entran por el mismo camino que una subida real:
 * se copian al disco `public` bajo la base del inquilino, la fila guarda `disk`, `path`,
 * `content_hash`, las medidas leídas del fichero y el máster, y la dirección pasa a ser la
 * ruta relativa `/storage/…` que devuelve el servicio de medios.
 *
 * **Miden 1920 × 1080**, y no es capricho: la escalera de derivadas se corta en el ancho del
 * máster, así que con originales de 800 la apertura de una portada de 1032 se serviría a 640 y
 * estirada. Son fotos de Unsplash servidas por Lorem Picsum a ese tamaño; `sources.json`
 * guarda autor y página de Unsplash de cada una, y de ahí sale `source_url`.
 *
 * **Sesenta ficheros para ciento setenta y seis direcciones**: la foto se asigna por la
 * dirección original —`assign()`, con `crc32` para la que no esté repartida—, así que dos piezas
 * con la misma dirección comparten foto y una re-siembra reparte igual. Cada foto acaba en unos
 * ocho titulares, más de los tres del reparto de la fixture; es lo que hay en el disco, y
 * repetir es preferible a inventarse variedad recortando la misma imagen.
 *
 * **El nombre del fichero no se aleatoriza**, al contrario que en una subida: el destino es
 * `media/{base}/unsplash-7.jpg`, así que volver a sembrar no renumera la biblioteca ni deja
 * copias del mismo JPEG con nombres distintos.
 */
final class SnkKilkanaDemoMediaLibrary
{
    /** Lo que el catálogo de Unsplash permite decir de estas sesenta: se pueden publicar. */
    private const LICENSE = 'Unsplash License';

    /**
     * Las fotos disponibles, ordenadas por ruta.
     *
     * El orden no significa nada pero es estable, y con eso basta: lo único que tiene que cumplir
     * es que el mismo índice dé siempre la misma foto.
     *
     * @var list<string>
     */
    private array $files;

    /** @var array<string, SnkKilkanaMediaModel> */
    private array $byFile = [];

    public function __construct(private readonly string $directory = __DIR__.'/data/media')
    {
        $files = glob($this->directory.'/*.jpg') ?: [];
        sort($files);
        $this->files = array_values($files);
    }

    /** @var array<string, array{author: string, url: string}>|null fichero → autor y página de Unsplash */
    private ?array $sources = null;

    /** Autor y página de Unsplash de cada foto, de `sources.json`; vacío si la carpeta no lo trae. */
    private function sources(): array
    {
        if ($this->sources === null) {
            $json = @file_get_contents($this->directory.'/sources.json');
            $this->sources = is_string($json) ? (json_decode($json, true) ?: []) : [];
        }

        return $this->sources;
    }

    /** Cuántas fotos hay de verdad en el disco. */
    public function size(): int
    {
        return count($this->files);
    }

    /**
     * Mete en la biblioteca **todas** las fotos del disco, las reclame alguien o no.
     *
     * Va antes del reparto para que los identificadores sigan el orden del directorio: con
     * las filas creándose a medida que una pieza las pide, el id de una foto dependería de en
     * qué orden estuvieran los artículos en el snapshot.
     *
     * @return int cuántas hay en la biblioteca al terminar
     */
    public function seedAll(): int
    {
        foreach ($this->files as $file) {
            $this->fromFile($file);
        }

        return count($this->byFile);
    }

    /**
     * El JPEG que le toca a una dirección del snapshot.
     *
     * Separado de `forUrl` porque es la mitad comprobable sin base de datos: el reparto es
     * aritmética sobre una lista de ficheros, y una prueba que lo recorra entero caza que
     * falte una foto o que el directorio esté vacío antes de que nadie siembre nada.
     *
     * Devuelve `null` solo si no hay ni una foto en el disco: una demo sin fototeca sigue
     * siendo una demo con artículos, y reventar aquí dejaría la base a medio sembrar.
     *
     * **El reparto no llega a las sesenta**, y las que sobran entran igual: las siembra
     * `seedAll()`. Una foto que ninguna pieza reclama es una fila con `usage_count` a cero y
     * sin alt —nada la enriquece—, así que llena de una vez las dos vistas de la mediateca
     * que existen para encontrar trabajo pendiente: «sin usar» y «Missing alt». Sin ellas las
     * dos salen a cero y no se pueden ni mirar.
     */
    public function fileFor(string $url): ?string
    {
        if ($this->files === []) {
            return null;
        }

        return $this->assigned[$url] ?? $this->files[crc32($url) % count($this->files)];
    }

    /** @var array<string, string> dirección del snapshot → fichero, por turno */
    private array $assigned = [];

    /**
     * Reparte las direcciones del snapshot entre los ficheros por CARGA, en orden estable.
     *
     * Con `crc32` a secas el reparto sería el de un hash: un fichero acabaría en veinticuatro
     * piezas y cuatro no saldrían nunca. Por turno de direcciones tampoco basta, porque una
     * dirección la comparten hasta ocho piezas. Así que cada dirección, de la más compartida a
     * la menos, va al fichero que menos piezas lleva: ninguno se queda sin estrenar y el más
     * cargado queda cerca de la media. Una dirección que no esté en la lista cae al hash, que
     * es lo que mantiene estable a quien no pase por aquí.
     *
     * @param  string[]  $urls  una entrada por pieza, con repeticiones
     */
    public function assign(array $urls): void
    {
        if ($this->files === []) {
            return;
        }
        $byUrl = array_count_values(array_values($urls));
        uksort($byUrl, fn (string $a, string $b) => [$byUrl[$b], $a] <=> [$byUrl[$a], $b]);

        $load = array_fill_keys($this->files, 0);
        foreach ($byUrl as $url => $posts) {
            $file = array_search(min($load), $load, true);
            $this->assigned[$url] = $file;
            $load[$file] += $posts;
        }
    }

    /**
     * La fila de una foto por su nombre de fichero, para lo que no se reparte por dirección.
     *
     * Los retratos de firma no salen del snapshot de artículos: cada uno es de una persona
     * concreta, así que se piden por nombre en vez de por `crc32`. Entran por el mismo camino
     * que las demás —al disco del inquilino, con hash y medidas—, que es lo que las hace
     * ficheros y no direcciones.
     */
    public function forName(string $name): ?SnkKilkanaMediaModel
    {
        $file = $this->directory.'/'.$name;

        return is_file($file) ? $this->fromFile($file) : null;
    }

    /** La fila de medio que le toca a una dirección del snapshot, creándola si hace falta. */
    public function forUrl(string $url): ?SnkKilkanaMediaModel
    {
        $file = $this->fileFor($url);

        return $file === null ? null : $this->fromFile($file);
    }

    /**
     * La fila de una foto concreta, creándola la primera vez que se pide.
     *
     * La memoria de proceso evita releer y rehashear el mismo JPEG las ocho veces que lo
     * reclaman ocho piezas; `content_hash` evita duplicar la fila entre ejecuciones, que es
     * el mismo criterio con el que el servicio deduplica una subida de verdad.
     */
    private function fromFile(string $file): SnkKilkanaMediaModel
    {
        if (isset($this->byFile[$file])) {
            return $this->byFile[$file];
        }

        $hash = hash_file('sha256', $file);
        $existing = SnkKilkanaMediaModel::withTrashed()->where('content_hash', $hash)->first();
        if ($existing) {
            return $this->byFile[$file] = $existing;
        }

        $name = basename($file);
        $path = 'media/'.DB::connection()->getDatabaseName().'/'.$name;
        // Se escribe siempre: la fila lleva el `content_hash` de ESTE fichero, y un disco con
        // la versión anterior bajo el mismo nombre dejaría la huella y la foto sin casar.
        Storage::disk('public')->put($path, (string) file_get_contents($file));

        $url = '/storage/'.$path;
        [$width, $height, $mime] = $this->dimensions($file);
        // El mismo máster que deja una subida real: sin él la foto no tiene derivadas y la
        // mediateca la pinta con el original entero, que en la rejilla son cuarenta y ocho
        // ficheros de cien kilobytes para miniaturas de 157 px.
        $master = app(SnkKilkanaImageService::class)->master($file, dirname($path), pathinfo($path, PATHINFO_FILENAME));

        $media = SnkKilkanaMediaModel::create([
            'url' => $url,
            'url_hash' => sha1($url),
            'content_hash' => $hash,
            'original_name' => $name,
            // Sin título: `enrichFromPosts()` le pone el del artículo que la estrena, que es
            // lo que hace buscable la biblioteca. `unsplash-7` no lo es.
            'disk' => 'public',
            'path' => $path,
            'mime' => $mime,
            'size_bytes' => filesize($file) ?: null,
            'width' => $width,
            'height' => $height,
            'rights_status' => SnkKilkanaMediaRightsEnum::Licensed,
            'license' => self::LICENSE,
            'source_url' => $this->sources()[$name]['url'] ?? null,
            'master_path' => $master['path'] ?? null,
            'master_width' => $master['width'] ?? null,
            'master_height' => $master['height'] ?? null,
        ]);
        // Las miniaturas del panel y la tarjeta de portada, por la cola: la demo se enseña a
        // compradores y la primera rejilla no puede esperar a que se generen cuarenta y ocho.
        // Con la cola en `sync` —las pruebas— no se encola: serían ochocientas derivadas dentro
        // de la siembra, y bajo demanda nacen igual.
        if ($media->variants_key && config('queue.default') !== 'sync') {
            SnkKilkanaMediaWarmJob::dispatch(DB::connection()->getDatabaseName(), (int) $media->getKey());
        }

        return $this->byFile[$file] = $media;
    }

    /**
     * Ancho, alto y tipo leídos del propio fichero.
     *
     * `getimagesize` lee la cabecera y no depende de GD, que en esta imagen viene sin JPEG.
     * Si el fichero no fuera una imagen se cae a nulos en vez de sembrar medidas inventadas:
     * el filtro por orientación de la mediateca exige las dos columnas y prefiere no verla a
     * verla mal colocada.
     *
     * @return array{0: ?int, 1: ?int, 2: ?string}
     */
    private function dimensions(string $file): array
    {
        $size = @getimagesize($file);

        return $size
            ? [$size[0], $size[1], $size['mime'] ?? null]
            : [null, null, null];
    }
}
