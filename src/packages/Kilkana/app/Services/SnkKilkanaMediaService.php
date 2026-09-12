<?php

namespace Packages\Kilkana\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Packages\Core\Support\CorePaginationSupport;
use Packages\Kilkana\Enums\SnkKilkanaMediaRightsEnum;
use Packages\Kilkana\Http\Resources\SnkKilkanaMediaResource;
use Packages\Kilkana\Jobs\SnkKilkanaMediaWarmJob;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use RuntimeException;
use Spatie\QueryBuilder\QueryBuilder;

class SnkKilkanaMediaService
{
    public function __construct(private readonly SnkKilkanaImageService $images) {}

    /**
     * El techo de `?fields=`: las columnas que la Resource declara, y ni una de fontanería.
     *
     * `usage_count`, `variants`, `tags` y `collections` no están: no son columnas, las cuelga
     * `filtered()` después de recortar y salen solas. Las cuatro de las que se calcula `variants`
     * tampoco, y eso es a propósito: quien pide `url,width,height` quiere una foto que se pueda
     * pintar, y las derivadas son parte de eso, no una columna que haya que saber nombrar.
     */
    public const FIELDS = [
        'id', 'url', 'original_name', 'title', 'mime', 'size_bytes', 'width', 'height', 'duration',
        'alt', 'caption', 'credit', 'license', 'rights_status', 'content_hash',
        'focal_x', 'focal_y', 'crop_x', 'crop_y', 'crop_w', 'crop_h', 'frames',
        'poster_at', 'poster_media_id', 'created_at', 'deleted_at',
    ];

    /**
     * Lo que se selecciona aunque nadie lo pida: la clave, que la precarga de las taxonomías
     * necesita para emparejar, y de lo que se calcula `variants`
     * (`SnkKilkanaImageService::payload()`).
     */
    private const PLUMBING = ['id', 'master_path', 'master_width', 'master_height', 'variants_key'];

    public function index(Request $request): array
    {
        $perPage = max(1, min((int) $request->integer('per_page', 24), 100));
        $page = $this->filtered($request)->paginate($perPage);

        return [
            'data' => SnkKilkanaMediaResource::collection($page->getCollection()),
            'meta' => CorePaginationSupport::meta($page),
            'facets' => $this->facets(),
            'scope' => $this->scope($request),
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

    /** Columnas y las dos taxonomías son una sola edición del inspector: van juntas o no van. */
    public function update(SnkKilkanaMediaModel $media, array $data): SnkKilkanaMediaModel
    {
        DB::transaction(function () use ($media, $data) {
            $columns = collect($data)->except(['tag_ids', 'collection_ids'])->all();
            // Los encuadres propios: solo proporciones que la fábrica produce, y vacío es nulo,
            // para que «ninguno» se lea igual venga de donde venga.
            if (array_key_exists('frames', $columns)) {
                $frames = array_intersect_key((array) ($columns['frames'] ?? []), array_filter(SnkKilkanaImageService::RATIOS));
                $columns['frames'] = $frames === [] ? null : $frames;
            }
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

    /** Marcar diez fotos y etiquetarlas es UNA acción: no puede quedarse en las cuatro primeras. */
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
        $header = $this->images->inspect($file->getRealPath());
        $dir = 'media/'.DB::connection()->getDatabaseName();
        $path = $file->store($dir, 'public');
        [$width, $height] = $header ? [$header['width'], $header['height']] : $this->dimensions($file->getRealPath(), $data);
        $master = $header && $this->images->isImage($header['mime'])
            ? $this->images->master($file->getRealPath(), $dir, pathinfo($path, PATHINFO_FILENAME))
            : null;
        $duration = $data['duration'] ?? null;
        $posterAt = null;
        // Vídeo y audio: lo que dice el fichero manda sobre lo que declaró quien subió, y un
        // vídeo gana un fotograma de portada que es su máster, con derivadas como una foto.
        if (! $header && $this->images->isMedia($file->getMimeType())) {
            $probe = $this->images->probe($file->getRealPath());
            $duration = $probe['duration'] ?? $duration;
            $width = $probe['width'] ?? $width;
            $height = $probe['height'] ?? $height;
            if (str_starts_with((string) $file->getMimeType(), 'video/')) {
                $posterAt = SnkKilkanaImageService::defaultPosterAt($duration);
                $master = $this->images->poster($file->getRealPath(), $dir, pathinfo($path, PATHINFO_FILENAME), $posterAt);
            }
        }

        try {
            $media = SnkKilkanaMediaModel::create([
                'url' => '/storage/'.$path,
                'original_name' => $file->getClientOriginalName(),
                'title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'mime' => $header['mime'] ?? $file->getMimeType(),
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
                'poster_at' => $master ? ($posterAt ?? null) : null,
                'uploaded_by' => $userId,
            ]);
            $this->warm($media);
        } catch (UniqueConstraintViolationException) {
            // El índice único gana la carrera que el `first()` de arriba no puede cerrar.
            //
            // Comprobar y luego insertar deja un hueco entre las dos mitades, y por ahí caben dos
            // peticiones con el MISMO fichero: las dos ven la biblioteca sin él, las dos escriben,
            // y la segunda choca. Pasa al soltar la misma foto dos veces en una tanda —el panel
            // sube en paralelo—, y sin esto el operador lee «rechazada» de un fichero que sí está
            // dentro. La respuesta correcta es la misma que si hubiera llegado un segundo después:
            // duplicada, con la fila que ganó.
            Storage::disk('public')->delete($path);
            if ($master) {
                Storage::disk('public')->delete($master['path']);
            }
            $winner = SnkKilkanaMediaModel::withTrashed()->where('content_hash', $hash)->first();

            // Sin fila que devolver el choque es de otra columna, y eso no es un duplicado:
            // se relanza para que se vea en vez de contarlo como «ya estaba».
            if (! $winner) {
                throw new RuntimeException('media upload clashed on something other than its content hash');
            }

            return ['media' => $this->serialize($winner), 'duplicate' => true];
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

        // ORDEN, no transacción: una transacción de base de datos no deshace un `unlink`.
        //
        // Se escribe el nuevo, la fila pasa a apuntarlo, y SOLO entonces se borra el viejo.
        // Borrar el viejo antes haría que un fallo al guardar —disco lleno, permisos, fichero
        // corrupto— dejara la fila apuntando a algo que no existe: la foto desaparecería del
        // periódico sin que nada fallara después. En este orden, si algo revienta por el
        // camino, lo peor que queda es un fichero huérfano en disco, que es basura y no una
        // pérdida.
        $previousDisk = $media->disk;
        $previousPath = $media->path;
        $previousMaster = $media->master_path;

        $header = $this->images->inspect($file->getRealPath());
        $dir = 'media/'.DB::connection()->getDatabaseName();
        $path = $file->store($dir, 'public');
        [$width, $height] = $header ? [$header['width'], $header['height']] : $this->dimensions($file->getRealPath(), $data);
        $master = $header && $this->images->isImage($header['mime'])
            ? $this->images->master($file->getRealPath(), $dir, pathinfo($path, PATHINFO_FILENAME))
            : null;
        $duration = $data['duration'] ?? null;
        $posterAt = null;
        if (! $header && $this->images->isMedia($file->getMimeType())) {
            $probe = $this->images->probe($file->getRealPath());
            $duration = $probe['duration'] ?? $duration;
            $width = $probe['width'] ?? $width;
            $height = $probe['height'] ?? $height;
            if (str_starts_with((string) $file->getMimeType(), 'video/')) {
                $posterAt = SnkKilkanaImageService::defaultPosterAt($duration);
                $master = $this->images->poster($file->getRealPath(), $dir, pathinfo($path, PATHINFO_FILENAME), $posterAt);
            }
        }

        $media->update([
            'url' => '/storage/'.$path,
            'mime' => $header['mime'] ?? $file->getMimeType(),
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
            'poster_at' => $master ? ($posterAt ?? null) : null,
        ]);
        $this->warm($media);
        if ($previousMaster && $previousMaster !== ($master['path'] ?? null)) {
            Storage::disk('public')->delete($previousMaster);
        }

        // Nadie lo referencia. La comparación cubre el caso de que el nombre generado
        // coincida con el anterior: borrarlo sería borrar el que se acaba de escribir.
        if ($previousDisk && $previousPath && $previousPath !== $path) {
            Storage::disk($previousDisk)->delete($previousPath);
        }

        return ['media' => $this->serialize($media), 'duplicate' => false];
    }

    /**
     * Otra portada para un vídeo: el fotograma del segundo elegido pasa a ser el máster, la
     * huella cambia y las derivadas nacen de él. `null` si no es un vídeo o ffmpeg no lo saca.
     */
    public function poster(SnkKilkanaMediaModel $media, int $at): ?SnkKilkanaMediaModel
    {
        if (! str_starts_with((string) $media->mime, 'video/') || ! $media->path) {
            return null;
        }
        $previous = $media->master_path;
        $disk = Storage::disk($media->disk ?: 'public');
        $master = $this->images->poster($disk->path($media->path), dirname($media->path), pathinfo($media->path, PATHINFO_FILENAME), $at);
        if ($master === null) {
            return null;
        }
        $media->forceFill(['master_path' => $master['path'], 'master_width' => $master['width'], 'master_height' => $master['height'], 'poster_at' => $at, 'poster_media_id' => null])->save();
        if ($previous && $previous !== $master['path']) {
            Storage::disk('public')->delete($previous);
        }
        $this->warm($media);

        return $this->serialize($media);
    }

    /**
     * Una foto de la biblioteca como portada del vídeo: su máster se copia como máster del
     * vídeo —copia y no referencia, para que borrar la foto no deje al vídeo sin portada— y
     * la huella cambia con el id de la foto. `null` si no es un vídeo o la foto no tiene máster.
     */
    public function posterFrom(SnkKilkanaMediaModel $media, SnkKilkanaMediaModel $photo): ?SnkKilkanaMediaModel
    {
        if (! str_starts_with((string) $media->mime, 'video/') || ! $media->path || ! $photo->master_path) {
            return null;
        }
        $disk = Storage::disk('public');
        $previous = $media->master_path;
        $path = dirname($media->path).'/m/'.pathinfo($media->path, PATHINFO_FILENAME).'-m'.$photo->getKey().'.jpg';
        if (! $disk->exists($photo->master_path) || ! $disk->copy($photo->master_path, $path)) {
            return null;
        }
        $media->forceFill([
            'master_path' => $path,
            'master_width' => $photo->master_width,
            'master_height' => $photo->master_height,
            'poster_at' => null,
            'poster_media_id' => $photo->getKey(),
        ])->save();
        if ($previous && $previous !== $path) {
            $disk->delete($previous);
        }
        $this->warm($media);

        return $this->serialize($media);
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

    private function filtered(Request $request): QueryBuilder
    {
        // El recorte primero y los agregados después: `allowedFields()` hace `select()`, así
        // que un `withCount` puesto antes desaparece en cuanto alguien pide campos.
        $query = QueryBuilder::for(SnkKilkanaMediaModel::query(), $request)
            ->allowedFields(...self::FIELDS)
            ->with(['tags', 'collections'])
            ->withCount(SnkKilkanaMediaModel::USES);

        if ($request->filled('fields')) {
            $query->addSelect(self::PLUMBING);
        }

        $status = $request->string('status')->toString();
        if ($status === 'trash') {
            $query->onlyTrashed();
        } elseif ($status === 'review') {
            $query->where('rights_status', SnkKilkanaMediaRightsEnum::Unknown);
        } elseif ($status === 'unused') {
            $query->unused();
        } elseif ($status === 'missing_alt') {
            // La faceta cuenta los que no tienen alt, y este filtro es lo que deja enseñar
            // cuáles. Es el único filtro de la mediateca que bloquea un despliegue: un fichero
            // sin texto alternativo tumba la puerta de toda ruta que lo use, y esta pantalla es
            // donde se resuelve.
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
        // Y por NOMBRE, que es lo que se lee en el raíl y lo que cabe en una URL compartible.
        // El nombre es único en la tabla, así que no es un filtro más laxo que el id: es el
        // mismo filtro sin obligar a quien pregunta a traerse antes el catálogo para traducir.
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

    private function whereKind(Builder|QueryBuilder $query, string $kind): void
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
     * Cuántos hay de cada familia y cuánto pesa la biblioteca, en UNA consulta.
     *
     * Las pestañas del panel llevan su cifra y la tira de estadísticas dice lo que ocupa el
     * archivo; eso son cuatro números, y pedirlos por separado son cuatro `LIKE 'image/%'`
     * sobre una columna sin índice. Agrupando por la familia del `mime` sale todo de un
     * recorrido: la misma tabla se lee una vez en vez de cuatro.
     *
     * Con un filtro delante cuenta DENTRO de él: las pestañas del panel son un filtro más, y
     * decir «68 imágenes» en una colección de quince es una cifra que contradice la lista que
     * tiene debajo.
     *
     * @return array{image: int, video: int, audio: int, bytes: int}
     */
    private function byFamily(?Builder $inside = null): array
    {
        $tally = ['image' => 0, 'video' => 0, 'audio' => 0, 'bytes' => 0];

        // Se agrupa por el `mime` entero y la familia se parte aquí: `SUBSTRING_INDEX` es de
        // MariaDB y las pruebas corren sobre SQLite, así que un SQL que solo funciona en
        // producción es un fallo que no se ve hasta producción. Los mimes distintos son una
        // docena, no una fila por foto.
        $rows = SnkKilkanaMediaModel::query()
            ->selectRaw('mime, COUNT(*) AS items, COALESCE(SUM(size_bytes), 0) AS bytes')
            ->when($inside, fn (Builder $query) => $query
                ->whereIn('kilkana_media.id', $inside->reorder()->select('kilkana_media.id')))
            ->groupBy('mime')
            ->get();

        foreach ($rows as $row) {
            $tally['bytes'] += (int) $row->bytes;
            $family = explode('/', (string) $row->mime)[0];
            if (array_key_exists($family, $tally)) {
                $tally[$family] += (int) $row->items;
            }
        }

        return $tally;
    }

    /**
     * Las mismas cuatro cifras de las pestañas, pero contadas dentro de lo que se está mirando
     * y sin el filtro de tipo: cada pestaña dice cuántos saldrían al pulsarla, con la
     * colección, la búsqueda y las fechas puestas. `facets` sigue siendo el archivo entero,
     * que es lo que mide la tira de estadisticas.
     *
     * @return array{all: int, image: int, video: int, audio: int}
     */
    private function scope(Request $request): array
    {
        $withoutKind = $request->duplicate(Arr::except($request->query->all(), ['kind']));
        // El Eloquent de debajo, sin el envoltorio de spatie: `whereIn` sabe anidar un `Builder`,
        // no un `QueryBuilder`, y la subconsulta reescribe el `select` de todos modos.
        $families = $this->byFamily($this->filtered($withoutKind)->getEloquentBuilder());

        return [
            'all' => $this->filtered($withoutKind)->count(),
            'image' => $families['image'],
            'video' => $families['video'],
            'audio' => $families['audio'],
        ];
    }

    private function facets(): array
    {
        $soon = Carbon::today()->addDays(30)->toDateString();

        return [
            ...$this->byFamily(),
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
     * Rellena lo que el artículo SÍ sabe de su portada: título, alt, pie, derechos y licencia.
     *
     * Solo rellena huecos —nunca pisa lo que alguien escribió a mano— y no toca etiquetas ni
     * colecciones: corre sobre todos los tenants, y copiar los tags del post o el nombre de su
     * sección dejaría en una ejecución cada fototeca con el vocabulario editorial dentro. Un
     * tag de medio describe el FICHERO —«retrato», «apaisado», «archivo»—, no el tema de la
     * noticia.
     */
    public function enrichFromPosts(): int
    {
        $enriched = 0;

        foreach (SnkKilkanaPostModel::query()->with('coverMedia')->get() as $post) {
            $media = $post->coverMedia;
            if (! $media) {
                continue;
            }

            $fromUnsplash = str_contains((string) $media->source_url, 'unsplash.com');
            $changes = [];

            // El nombre que trae el fichero descargado no es un título: `photo-1a2b3c`.
            if (! $media->title || preg_match('/^(photo|unsplash)-[a-z0-9-]+$/i', $media->title)) {
                $changes['title'] = $post->title;
            }
            if (! $media->alt) {
                $changes['alt'] = $post->title;
            }
            if (! $media->caption) {
                $changes['caption'] = $post->excerpt;
            }
            if ($fromUnsplash && $media->rights_status->needsReview()) {
                $changes['rights_status'] = SnkKilkanaMediaRightsEnum::Licensed;
            }
            if ($fromUnsplash && ! $media->license) {
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
     * Quién usa este fichero, por su nombre y diciendo cómo.
     *
     * Las tres formas de estar en uso son las de `SnkKilkanaMediaModel::USES`, y salen de ahí
     * para que una cuarta clave foránea hacia un medio aparezca aquí sola en vez de dejar una
     * lista corta que parece completa.
     *
     * El tope es por relación y no del total: con un tope global, una foto usada en cuarenta
     * portadas dejaría fuera la firma que también la lleva de retrato, que es justo la que
     * sorprende al borrarla.
     *
     * @return list<array{kind: string, id: int, title: string}>
     */
    public function uses(SnkKilkanaMediaModel $media, int $perRelation = 8): array
    {
        $title = [
            'coveredPosts' => 'title',
            'socialPosts' => 'title',
            'avatarAuthors' => 'name',
        ];

        $uses = [];
        foreach (SnkKilkanaMediaModel::USES as $relation) {
            foreach ($media->{$relation}()->limit($perRelation)->get() as $row) {
                $uses[] = [
                    'kind' => $relation,
                    'id' => (int) $row->id,
                    'title' => (string) $row->{$title[$relation]},
                ];
            }
        }

        return $uses;
    }

    /**
     * La ficha recién escrita, con la MISMA forma que trae el listado.
     *
     * Actualizar, restaurar, subir y reemplazar devuelven una fila que el panel mete en la lista que
     * ya tiene delante. Si esa fila viniera con más campos que las demás, la lista dejaría de
     * ser homogénea justo en la fila que se acaba de tocar.
     */
    public function serialize(SnkKilkanaMediaModel $media): SnkKilkanaMediaModel
    {
        return $media->fresh(['tags', 'collections'])->loadCount(SnkKilkanaMediaModel::USES);
    }
}
