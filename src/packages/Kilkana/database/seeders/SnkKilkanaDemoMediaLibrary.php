<?php

namespace Packages\Kilkana\Database\Seeders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Packages\Kilkana\Enums\SnkKilkanaMediaRightsEnum;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;

/**
 * La fototeca de la demo, servida desde el disco del periódico y no desde un tercero.
 *
 * El snapshot trae en cada pieza una dirección de `loremflickr`, que es un servicio de fotos
 * de relleno. Una fila con esa dirección y nada más no es un fichero: no tiene ancho, ni alto,
 * ni peso, ni `content_hash`, así que la mediateca no puede deduplicar, ni filtrar por
 * orientación, ni decir cuánto ocupa; y el lector paga DNS, TLS y una cookie de tercero por
 * cada foto —que es lo que dejaba la nota de buenas prácticas del artículo en 96—.
 *
 * Aquí las sesenta fotos de `data/media/` entran por el mismo camino que una subida real:
 * se copian al disco `public` bajo la base del inquilino, la fila guarda `disk`, `path`,
 * `content_hash` y las medidas leídas del fichero, y la dirección pasa a ser la ruta
 * relativa `/storage/…` que devuelve el servicio de medios.
 *
 * **Sesenta ficheros para ciento setenta y seis direcciones**: la foto se elige por
 * `crc32` de la dirección original, así que dos piezas que compartían foto la siguen
 * compartiendo y una re-siembra reparte igual. Cada foto acaba en unos ocho titulares, más
 * de los tres que buscaba el reparto de la fixture; es lo que hay en el disco, y repetir es
 * preferible a inventarse variedad recortando la misma imagen.
 *
 * **El nombre del fichero no se aleatoriza**, al contrario que en una subida: el destino es
 * `media/{base}/unsplash-7.jpg`, así que volver a sembrar no renumera la biblioteca ni deja
 * copias del mismo JPEG con nombres distintos.
 */
final class SnkKilkanaDemoMediaLibrary
{
    /** Lo que el catálogo de Unsplash permite decir de estas sesenta: se pueden publicar. */
    private const LICENCIA = 'Unsplash License';

    /**
     * Las fotos disponibles, en el orden que da el sistema de ficheros.
     *
     * El orden es arbitrario pero estable, y con eso basta: lo único que tiene que cumplir
     * es que el mismo índice dé siempre la misma foto.
     *
     * @var list<string>
     */
    private array $ficheros;

    /** @var array<string, SnkKilkanaMediaModel> */
    private array $porFichero = [];

    public function __construct(private readonly string $directorio = __DIR__.'/data/media')
    {
        $ficheros = glob($this->directorio.'/*.jpg') ?: [];
        sort($ficheros);
        $this->ficheros = array_values($ficheros);
    }

    /** Cuántas fotos hay de verdad en el disco. */
    public function size(): int
    {
        return count($this->ficheros);
    }

    /**
     * Mete en la biblioteca **todas** las fotos del disco, las reclame alguien o no.
     *
     * Va antes del reparto para que los identificadores sigan el orden del directorio: con
     * las filas creándose a medida que una pieza las pide, el id de una foto dependía de en
     * qué orden estuvieran los artículos en el snapshot.
     *
     * @return int cuántas hay en la biblioteca al terminar
     */
    public function seedAll(): int
    {
        foreach ($this->ficheros as $fichero) {
            $this->fromFile($fichero);
        }

        return count($this->porFichero);
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
        if ($this->ficheros === []) {
            return null;
        }

        return $this->ficheros[crc32($url) % count($this->ficheros)];
    }

    /** La fila de medio que le toca a una dirección del snapshot, creándola si hace falta. */
    public function forUrl(string $url): ?SnkKilkanaMediaModel
    {
        $fichero = $this->fileFor($url);

        return $fichero === null ? null : $this->fromFile($fichero);
    }

    /**
     * La fila de una foto concreta, creándola la primera vez que se pide.
     *
     * La memoria de proceso evita releer y rehashear el mismo JPEG las ocho veces que lo
     * reclaman ocho piezas; `content_hash` evita duplicar la fila entre ejecuciones, que es
     * el mismo criterio con el que el servicio deduplica una subida de verdad.
     */
    private function fromFile(string $fichero): SnkKilkanaMediaModel
    {
        if (isset($this->porFichero[$fichero])) {
            return $this->porFichero[$fichero];
        }

        $hash = hash_file('sha256', $fichero);
        $existente = SnkKilkanaMediaModel::withTrashed()->where('content_hash', $hash)->first();
        if ($existente) {
            return $this->porFichero[$fichero] = $existente;
        }

        $nombre = basename($fichero);
        $ruta = 'media/'.DB::connection()->getDatabaseName().'/'.$nombre;
        if (! Storage::disk('public')->exists($ruta)) {
            Storage::disk('public')->put($ruta, (string) file_get_contents($fichero));
        }

        $url = '/storage/'.$ruta;
        [$ancho, $alto, $mime] = $this->medidas($fichero);

        return $this->porFichero[$fichero] = SnkKilkanaMediaModel::create([
            'url' => $url,
            'url_hash' => sha1($url),
            'content_hash' => $hash,
            'original_name' => $nombre,
            // Sin título: `enrichFromPosts()` le pone el del artículo que la estrena, que es
            // lo que hace buscable la biblioteca. `unsplash-7` no lo es.
            'disk' => 'public',
            'path' => $ruta,
            'mime' => $mime,
            'size_bytes' => filesize($fichero) ?: null,
            'width' => $ancho,
            'height' => $alto,
            'rights_status' => SnkKilkanaMediaRightsEnum::Licensed,
            'license' => self::LICENCIA,
        ]);
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
    private function medidas(string $fichero): array
    {
        $tamano = @getimagesize($fichero);

        return $tamano
            ? [$tamano[0], $tamano[1], $tamano['mime'] ?? null]
            : [null, null, null];
    }
}
