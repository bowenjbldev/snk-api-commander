<?php

namespace Packages\Kilkana\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Packages\Kilkana\Enums\SnkKilkanaMediaRightsEnum;
use Packages\Kilkana\Jobs\SnkKilkanaMediaWarmJob;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use RuntimeException;

class SnkKilkanaMediaService
{
    public function __construct(private readonly SnkKilkanaImageService $images) {}

    /**
     * Lo que una ficha publica al panel, y **nada mas**.
     *
     * Lista blanca y no lista negra, igual que `FILA_DEL_LISTADO` en los articulos: una
     * columna nueva en `kilkana_media` no se publica sola el dia que alguien la anada. Aqui
     * no era hipotetico —la fila salia entera— y lo que se colaba era `disk` y `path`, que son
     * exactamente los dos campos con los que se borra un fichero del disco. Leerlos no borra
     * nada; publicarlos es contarle a cualquiera con sesion como esta montado el
     * almacenamiento del inquilino, y no hay una sola pantalla que los pinte.
     *
     * Fuera tambien lo editorial que el panel no ensena hoy —`caption`, `description`,
     * `source_url`, la caducidad de derechos— y `uploaded_by`. Que entren es anadirlos aqui,
     * que es justo el momento en el que alguien decide publicarlos.
     */
    public const FILA_DE_LA_BIBLIOTECA = [
        'id', 'url', 'original_name', 'title', 'mime', 'size_bytes', 'width', 'height',
        'duration', 'alt', 'credit', 'license', 'rights_status', 'content_hash',
        'focal_x', 'focal_y', 'crop_x', 'crop_y', 'crop_w', 'crop_h', 'variants',
        'created_at', 'deleted_at', 'usage_count', 'tags', 'collections',
    ];

    public function asLibraryRow(SnkKilkanaMediaModel $media): SnkKilkanaMediaModel
    {
        $media->setVisible(self::FILA_DE_LA_BIBLIOTECA);
        // De las dos taxonomias basta el par que dibuja un chip: el id para escribir y el
        // nombre para leer. Los pivotes no pintan nada.
        $media->tags->each->setVisible(['id', 'name']);
        $media->collections->each->setVisible(['id', 'name']);

        return $media;
    }

    public function index(Request $request): array
    {
        $perPage = max(1, min((int) $request->integer('per_page', 24), 100));
        $page = $this->filtered($request)->paginate($perPage);

        return [
            'data' => array_map($this->asLibraryRow(...), $page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'facets' => $this->facets(),
        ];
    }

    public function find($id): ?SnkKilkanaMediaModel
    {
        return SnkKilkanaMediaModel::query()->find($id);
    }

    public function findTrashed($id): ?SnkKilkanaMediaModel
    {
        return SnkKilkanaMediaModel::onlyTrashed()->find($id);
    }

    public function findWithTrashed($id): ?SnkKilkanaMediaModel
    {
        return SnkKilkanaMediaModel::withTrashed()->find($id);
    }

    /** Columnas y las dos taxonomias son una sola edicion del inspector: van juntas o no van. */
    public function update(SnkKilkanaMediaModel $media, array $data): SnkKilkanaMediaModel
    {
        DB::transaction(function () use ($media, $data) {
            $columns = collect($data)->except(['tag_ids', 'collection_ids'])->all();
            if ($columns) {
                $media->update($columns);
                if ($media->wasChanged('variants_key')) {
                    $this->warm($media);
                }
            }
            if (array_key_exists('tag_ids', $data)) {
                $media->tags()->sync($data['tag_ids']);
            }
            if (array_key_exists('collection_ids', $data)) {
                $media->collections()->sync($data['collection_ids']);
            }
        });

        return $this->serialize($media);
    }

    public function trash(SnkKilkanaMediaModel $media): void
    {
        $media->delete();
    }

    public function restore(SnkKilkanaMediaModel $media): SnkKilkanaMediaModel
    {
        $media->restore();

        return $this->serialize($media);
    }

    public function forceDestroy(SnkKilkanaMediaModel $media): void
    {
        if ($media->disk && $media->path) {
            Storage::disk($media->disk)->delete($media->path);
        }
        $media->forceDelete();
    }

    /** Marcar diez fotos y etiquetarlas es UNA accion: no puede quedarse en las cuatro primeras. */
    public function bulk(array $ids, string $action, array $tagIds = [], array $collectionIds = []): void
    {
        DB::transaction(fn () => $this->applyBulk($ids, $action, $tagIds, $collectionIds));
    }

    private function applyBulk(array $ids, string $action, array $tagIds, array $collectionIds): void
    {
        switch ($action) {
            case 'trash':
                SnkKilkanaMediaModel::query()->whereIn('id', $ids)->delete();
                break;
            case 'restore':
                SnkKilkanaMediaModel::onlyTrashed()->whereIn('id', $ids)->restore();
                break;
            case 'tag':
                foreach (SnkKilkanaMediaModel::query()->whereIn('id', $ids)->get() as $media) {
                    $media->tags()->syncWithoutDetaching($tagIds);
                }
                break;
            case 'untag':
                foreach (SnkKilkanaMediaModel::query()->whereIn('id', $ids)->get() as $media) {
                    $media->tags()->detach($tagIds);
                }
                break;
            case 'collect':
                foreach (SnkKilkanaMediaModel::query()->whereIn('id', $ids)->get() as $media) {
                    $media->collections()->syncWithoutDetaching($collectionIds);
                }
                break;
            case 'uncollect':
                foreach (SnkKilkanaMediaModel::query()->whereIn('id', $ids)->get() as $media) {
                    $media->collections()->detach($collectionIds);
                }
                break;
        }
    }

    /** @return array{media: SnkKilkanaMediaModel, duplicate: bool} */
    public function upload(array $data, int $userId): array
    {
        $file = $data['file'];
        $hash = hash_file('sha256', $file->getRealPath());
        $existing = SnkKilkanaMediaModel::withTrashed()->where('content_hash', $hash)->first();

        if ($existing) {
            return ['media' => $this->serialize($existing), 'duplicate' => true];
        }

        // La cabecera antes que nada: lo que no cabe en memoria se rechaza aquí, con mensaje,
        // y no dentro de GD con un error fatal.
        $cabecera = $this->images->inspect($file->getRealPath());
        $dir = 'media/'.DB::connection()->getDatabaseName();
        $path = $file->store($dir, 'public');
        [$width, $height] = $cabecera ? [$cabecera['width'], $cabecera['height']] : $this->dimensions($file->getRealPath(), $data);
        $master = $cabecera && $this->images->isImage($cabecera['mime'])
            ? $this->images->master($file->getRealPath(), $dir, pathinfo($path, PATHINFO_FILENAME))
            : null;
        $duration = $data['duration'] ?? null;
        // Vídeo y audio: lo que dice el fichero manda sobre lo que declaró quien subió, y un
        // vídeo gana un fotograma de portada que es su máster, con derivadas como una foto.
        if (! $cabecera && $this->images->isMedia($file->getMimeType())) {
            $sonda = $this->images->probe($file->getRealPath());
            $duration = $sonda['duration'] ?? $duration;
            $width = $sonda['width'] ?? $width;
            $height = $sonda['height'] ?? $height;
            if (str_starts_with((string) $file->getMimeType(), 'video/')) {
                $master = $this->images->poster($file->getRealPath(), $dir, pathinfo($path, PATHINFO_FILENAME), $duration);
            }
        }

        try {
            $media = SnkKilkanaMediaModel::create([
                'url' => '/storage/'.$path,
                'original_name' => $file->getClientOriginalName(),
                'title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'mime' => $cabecera['mime'] ?? $file->getMimeType(),
                'width' => $width,
                'height' => $height,
                'duration' => $duration,
                'size_bytes' => $file->getSize(),
                'content_hash' => $hash,
                'disk' => 'public',
                'path' => $path,
                'master_path' => $master['path'] ?? null,
                'master_width' => $master['width'] ?? null,
                'master_height' => $master['height'] ?? null,
                'uploaded_by' => $userId,
            ]);
            $this->warm($media);
        } catch (UniqueConstraintViolationException) {
            // El indice unico gana la carrera que el `first()` de arriba no puede cerrar.
            //
            // Comprobar y luego insertar deja un hueco entre las dos mitades, y por ahi caben dos
            // peticiones con el MISMO fichero: las dos ven la biblioteca sin el, las dos escriben,
            // y la segunda choca. Pasaba soltando la misma foto dos veces en una tanda —el panel
            // sube en paralelo—, y el operador leia «rechazada» de un fichero que si esta dentro.
            // La respuesta correcta es la misma que si hubiera llegado un segundo despues:
            // duplicada, con la fila que gano.
            Storage::disk('public')->delete($path);
            if ($master) {
                Storage::disk('public')->delete($master['path']);
            }
            $ganadora = SnkKilkanaMediaModel::withTrashed()->where('content_hash', $hash)->first();

            // Sin fila que devolver el choque era de otra columna, y eso no es un duplicado:
            // se relanza para que se vea en vez de contarlo como «ya estaba».
            if (! $ganadora) {
                throw new RuntimeException('media upload clashed on something other than its content hash');
            }

            return ['media' => $this->serialize($ganadora), 'duplicate' => true];
        }

        return ['media' => $this->serialize($media), 'duplicate' => false];
    }

    /** @return array{media: SnkKilkanaMediaModel, duplicate: bool} */
    public function replace(SnkKilkanaMediaModel $media, array $data): array
    {
        $file = $data['file'];
        $hash = hash_file('sha256', $file->getRealPath());
        $clash = SnkKilkanaMediaModel::withTrashed()
            ->where('content_hash', $hash)
            ->where('id', '!=', $media->id)
            ->first();

        if ($clash) {
            return ['media' => $this->serialize($clash), 'duplicate' => true];
        }

        // ORDEN, no transaccion: una transaccion de base de datos no deshace un `unlink`.
        //
        // Esto borraba el fichero viejo ANTES de escribir el nuevo, asi que un fallo al
        // guardar —disco lleno, permisos, fichero corrupto— dejaba la fila apuntando a algo
        // que ya no existia: la foto desaparecia del periodico sin que nada fallara despues.
        //
        // Ahora se escribe el nuevo, la fila pasa a apuntarlo, y SOLO entonces se borra el
        // viejo. Si algo revienta por el camino, lo peor que queda es un fichero huerfano en
        // disco, que es basura y no una perdida.
        $anteriorDisk = $media->disk;
        $anteriorPath = $media->path;
        $anteriorMaster = $media->master_path;

        $cabecera = $this->images->inspect($file->getRealPath());
        $dir = 'media/'.DB::connection()->getDatabaseName();
        $path = $file->store($dir, 'public');
        [$width, $height] = $cabecera ? [$cabecera['width'], $cabecera['height']] : $this->dimensions($file->getRealPath(), $data);
        $master = $cabecera && $this->images->isImage($cabecera['mime'])
            ? $this->images->master($file->getRealPath(), $dir, pathinfo($path, PATHINFO_FILENAME))
            : null;
        $duration = $data['duration'] ?? null;
        if (! $cabecera && $this->images->isMedia($file->getMimeType())) {
            $sonda = $this->images->probe($file->getRealPath());
            $duration = $sonda['duration'] ?? $duration;
            $width = $sonda['width'] ?? $width;
            $height = $sonda['height'] ?? $height;
            if (str_starts_with((string) $file->getMimeType(), 'video/')) {
                $master = $this->images->poster($file->getRealPath(), $dir, pathinfo($path, PATHINFO_FILENAME), $duration);
            }
        }

        $media->update([
            'url' => '/storage/'.$path,
            'mime' => $cabecera['mime'] ?? $file->getMimeType(),
            'width' => $width,
            'height' => $height,
            'duration' => $duration,
            'size_bytes' => $file->getSize(),
            'content_hash' => $hash,
            'disk' => 'public',
            'path' => $path,
            'master_path' => $master['path'] ?? null,
            'master_width' => $master['width'] ?? null,
            'master_height' => $master['height'] ?? null,
        ]);
        $this->warm($media);
        if ($anteriorMaster && $anteriorMaster !== ($master['path'] ?? null)) {
            Storage::disk('public')->delete($anteriorMaster);
        }

        // Ya no lo referencia nadie. La comparacion cubre el caso de que el nombre generado
        // coincidiera con el anterior: borrarlo seria borrar el que se acaba de escribir.
        if ($anteriorDisk && $anteriorPath && $anteriorPath !== $path) {
            Storage::disk($anteriorDisk)->delete($anteriorPath);
        }

        return ['media' => $this->serialize($media), 'duplicate' => false];
    }

    /** Lo que seguro se pide nada más publicar, por la cola; sin worker no pasa nada: nace bajo demanda. */
    private function warm(SnkKilkanaMediaModel $media): void
    {
        if ($media->master_path && $media->variants_key) {
            SnkKilkanaMediaWarmJob::dispatch(DB::connection()->getDatabaseName(), (int) $media->getKey());
        }
    }

    private function dimensions(string $realPath, array $data): array
    {
        $size = @getimagesize($realPath);

        return $size ? [$size[0], $size[1]] : [$data['width'] ?? null, $data['height'] ?? null];
    }

    private function filtered(Request $request): Builder
    {
        $query = SnkKilkanaMediaModel::query()
            ->with(['tags', 'collections'])
            ->withCount(SnkKilkanaMediaModel::USOS);

        $status = $request->string('status')->toString();
        if ($status === 'trash') {
            $query->onlyTrashed();
        } elseif ($status === 'review') {
            $query->where('rights_status', SnkKilkanaMediaRightsEnum::Unknown);
        } elseif ($status === 'unused') {
            $query->unused();
        } elseif ($status === 'missing_alt') {
            // La faceta existia y el filtro no, asi que el panel podia decir «19 sin alt» y no
            // tenia como ensenar cuales. Es el unico filtro de la mediateca que bloquea un
            // despliegue: un fichero sin texto alternativo tumba la puerta de toda ruta que lo
            // use, y esta pantalla es donde se resuelve.
            $query->where(fn (Builder $sub) => $sub->whereNull('alt')->orWhere('alt', ''));
        }

        if ($search = $request->string('q')->trim()->toString()) {
            $query->where(function (Builder $sub) use ($search) {
                foreach (['title', 'original_name', 'alt', 'caption', 'credit', 'description'] as $column) {
                    $sub->orWhere($column, 'like', "%{$search}%");
                }
            });
        }
        if ($kind = $request->string('kind')->toString()) {
            $this->whereKind($query, $kind);
        }
        if ($tagId = $request->integer('tag_id')) {
            $query->whereHas('tags', fn (Builder $sub) => $sub->where('kilkana_media_tags.id', $tagId));
        }
        if ($collectionId = $request->integer('collection_id')) {
            $query->whereHas('collections', fn (Builder $sub) => $sub->where('kilkana_media_collections.id', $collectionId));
        }
        // Y por NOMBRE, que es lo que se lee en el rail y lo que cabe en una URL compartible.
        // El nombre es unico en la tabla, asi que no es un filtro mas laxo que el id: es el
        // mismo filtro sin obligar a quien pregunta a traerse antes el catalogo para traducir.
        if ($collection = $request->string('collection')->trim()->toString()) {
            $query->whereHas('collections', fn (Builder $sub) => $sub->where('kilkana_media_collections.name', $collection));
        }

        if ($orientation = $request->string('orientation')->toString()) {
            $query->whereNotNull('width')->whereNotNull('height');
            if ($orientation === 'landscape') {
                $query->whereColumn('width', '>', 'height');
            } elseif ($orientation === 'portrait') {
                $query->whereColumn('height', '>', 'width');
            } elseif ($orientation === 'square') {
                $query->whereColumn('width', '=', 'height');
            }
        }

        if ($from = $request->string('from')->toString()) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->string('to')->toString()) {
            $query->whereDate('created_at', '<=', $to);
        }
        if ($uploadedBy = $request->integer('uploaded_by')) {
            $query->where('uploaded_by', $uploadedBy);
        }

        return match ($request->string('sort')->toString()) {
            'oldest' => $query->orderBy('created_at'),
            'name' => $query->orderBy('title'),
            'largest' => $query->orderByDesc('size_bytes'),
            default => $query->orderByDesc('created_at'),
        };
    }

    private function whereKind(Builder $query, string $kind): void
    {
        match ($kind) {
            'image' => $query->where('mime', 'like', 'image/%'),
            'video' => $query->where('mime', 'like', 'video/%'),
            'audio' => $query->where('mime', 'like', 'audio/%'),
            'document' => $query->where(fn (Builder $sub) => $sub
                ->whereNull('mime')
                ->orWhere(fn (Builder $inner) => $inner
                    ->where('mime', 'not like', 'image/%')
                    ->where('mime', 'not like', 'video/%')
                    ->where('mime', 'not like', 'audio/%'))),
            default => null,
        };
    }

    /**
     * Cuantos hay de cada familia y cuanto pesa la biblioteca, en UNA consulta.
     *
     * Las pestanas del panel llevan su cifra y la tira de estadisticas dice lo que ocupa el
     * archivo; eso son cuatro numeros, y pedirlos por separado son cuatro `LIKE 'image/%'`
     * sobre una columna sin indice. Agrupando por la familia del `mime` sale todo de un
     * recorrido: la misma tabla se lee una vez en vez de cuatro.
     *
     * @return array{image: int, video: int, audio: int, bytes: int}
     */
    private function porFamilia(): array
    {
        $conteo = ['image' => 0, 'video' => 0, 'audio' => 0, 'bytes' => 0];

        // Se agrupa por el `mime` entero y la familia se parte aqui: `SUBSTRING_INDEX` es de
        // MariaDB y las pruebas corren sobre SQLite, asi que un SQL que solo funciona en
        // produccion es un fallo que no se ve hasta produccion. Los mimes distintos son una
        // docena, no una fila por foto.
        $filas = SnkKilkanaMediaModel::query()
            ->selectRaw('mime, COUNT(*) AS piezas, COALESCE(SUM(size_bytes), 0) AS peso')
            ->groupBy('mime')
            ->get();

        foreach ($filas as $fila) {
            $conteo['bytes'] += (int) $fila->peso;
            $familia = explode('/', (string) $fila->mime)[0];
            if (array_key_exists($familia, $conteo)) {
                $conteo[$familia] += (int) $fila->piezas;
            }
        }

        return $conteo;
    }

    private function facets(): array
    {
        $soon = Carbon::today()->addDays(30)->toDateString();

        return [
            ...$this->porFamilia(),
            'all' => SnkKilkanaMediaModel::query()->count(),
            'review' => SnkKilkanaMediaModel::query()->where('rights_status', SnkKilkanaMediaRightsEnum::Unknown)->count(),
            'unused' => SnkKilkanaMediaModel::query()->unused()->count(),
            'missing_alt' => SnkKilkanaMediaModel::query()
                ->where(fn (Builder $query) => $query->whereNull('alt')->orWhere('alt', ''))
                ->count(),
            'expiring' => SnkKilkanaMediaModel::query()
                ->whereNotNull('rights_expires_at')
                // Sin `whereDate`: envolver la columna en una función impide usar el índice,
                // y la columna ya es de tipo fecha.
                ->where('rights_expires_at', '<=', $soon)
                ->count(),
            'trash' => SnkKilkanaMediaModel::onlyTrashed()->count(),
        ];
    }

    /**
     * Rellena lo que el articulo SI sabe de su portada: titulo, alt, pie, derechos y licencia.
     *
     * Solo rellena huecos —nunca pisa lo que alguien escribio a mano— y no toca etiquetas ni
     * colecciones. Llego a copiar los tags del post y el nombre de su seccion, y como corre
     * sobre todos los tenants bastaba una ejecucion para dejar cada fototeca con el
     * vocabulario editorial dentro: un tag de medio describe el FICHERO —«retrato»,
     * «apaisado», «archivo»—, no el tema de la noticia.
     */
    public function enrichFromPosts(): int
    {
        $enriched = 0;

        foreach (SnkKilkanaPostModel::query()->with('coverMedia')->get() as $post) {
            $media = $post->coverMedia;
            if (! $media) {
                continue;
            }

            $deUnsplash = str_contains((string) $media->source_url, 'unsplash.com');
            $changes = [];

            // El nombre que trae el fichero descargado no es un titulo: `photo-1a2b3c`.
            if (! $media->title || preg_match('/^(photo|unsplash)-[a-z0-9-]+$/i', $media->title)) {
                $changes['title'] = $post->title;
            }
            if (! $media->alt) {
                $changes['alt'] = $post->title;
            }
            if (! $media->caption) {
                $changes['caption'] = $post->excerpt;
            }
            if (! $media->description) {
                $changes['description'] = "Editorial cover for: {$post->title}";
            }
            if ($deUnsplash && $media->rights_status->needsReview()) {
                $changes['rights_status'] = SnkKilkanaMediaRightsEnum::Licensed;
            }
            if ($deUnsplash && ! $media->license) {
                $changes['license'] = 'Unsplash License';
            }

            if ($changes) {
                $media->update($changes);
                $enriched++;
            }
        }

        return $enriched;
    }

    /**
     * Quien usa este fichero, por su nombre y diciendo como.
     *
     * Las tres formas de estar en uso son las de `SnkKilkanaMediaModel::USOS`, y salen de ahi
     * para que una cuarta clave foranea hacia un medio aparezca aqui sola en vez de dejar una
     * lista corta que parece completa.
     *
     * El tope es por relacion y no del total: con un tope global, una foto usada en cuarenta
     * portadas dejaria fuera la firma que tambien la lleva de retrato, que es justo la que
     * sorprende al borrarla.
     *
     * @return list<array{kind: string, id: int, title: string}>
     */
    public function uses(SnkKilkanaMediaModel $media, int $porRelacion = 8): array
    {
        $titulo = [
            'coveredPosts' => 'title',
            'socialPosts' => 'title',
            'avatarAuthors' => 'name',
        ];

        $usos = [];
        foreach (SnkKilkanaMediaModel::USOS as $relacion) {
            foreach ($media->{$relacion}()->limit($porRelacion)->get() as $fila) {
                $usos[] = [
                    'kind' => $relacion,
                    'id' => (int) $fila->id,
                    'title' => (string) $fila->{$titulo[$relacion]},
                ];
            }
        }

        return $usos;
    }

    /**
     * La ficha recien escrita, con la MISMA forma que trae el listado.
     *
     * Update, restore, upload y reemplazo devuelven una fila que el panel mete en la lista que
     * ya tiene delante. Si esa fila viniera con mas campos que las demas, la lista dejaria de
     * ser homogenea justo en la fila que se acaba de tocar.
     */
    public function serialize(SnkKilkanaMediaModel $media): SnkKilkanaMediaModel
    {
        return $this->asLibraryRow(
            $media->fresh(['tags', 'collections'])->loadCount(SnkKilkanaMediaModel::USOS)
        );
    }
}
