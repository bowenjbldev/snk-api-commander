<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaModel;

/**
 * Biblioteca de medios estilo WordPress: subida al disco del tenant con dedup por
 * hash de contenido, metadatos editoriales y de derechos, taxonomías (tags +
 * colecciones) y papelera (soft delete). El index sirve {data, meta, facets}: la
 * lista paginada y filtrada + los contadores globales de las pestañas.
 */
#[Group('Media')]
class SnkKilkanaMediaController extends CoreBaseApiController
{
    protected string $modelClass = SnkKilkanaMediaModel::class;

    protected string $indexPermission = 'index media';

    protected string $showPermission = 'show media';

    protected string $storePermission = 'create media';

    protected string $updatePermission = 'update media';

    protected string $destroyPermission = 'delete media';

    /** Mimes aceptados en subida/reemplazo (alineados con MEDIA_ACCEPT del frontend). */
    private const MIMES = 'jpeg,jpg,png,webp,avif,gif,mp4,webm,mp3,m4a,wav,ogg,pdf';

    // Sin parámetro: la firma la fija CoreBaseApiController::index(). La request se
    // toma del helper, como hace la propia base en parseWithParam().
    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        $request = request();
        $perPage = max(1, min((int) $request->integer('per_page', 24), 100));

        $page = $this->filtered($request)->paginate($perPage);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'facets' => $this->facets(),
        ]);
    }

    public function update(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }

        $media = SnkKilkanaMediaModel::query()->find($id);
        if (! $media) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $data = $request->validate([
            'title' => 'sometimes|nullable|string|max:500',
            'alt' => 'sometimes|nullable|string|max:500',
            'caption' => 'sometimes|nullable|string|max:500',
            'description' => 'sometimes|nullable|string',
            'credit' => 'sometimes|nullable|string|max:500',
            'source_url' => 'sometimes|nullable|string|max:2048',
            'rights_status' => 'sometimes|string|in:unknown,owned,licensed,creative_commons,public_domain',
            'license' => 'sometimes|nullable|string|max:500',
            'rights_expires_at' => 'sometimes|nullable|date',
            'focal_x' => 'sometimes|numeric|between:0,1',
            'focal_y' => 'sometimes|numeric|between:0,1',
            'tag_ids' => 'sometimes|array',
            'tag_ids.*' => 'integer',
            'collection_ids' => 'sometimes|array',
            'collection_ids.*' => 'integer',
        ]);

        $columns = collect($data)->except(['tag_ids', 'collection_ids'])->all();
        if ($columns) {
            $media->update($columns);
        }
        if (array_key_exists('tag_ids', $data)) {
            $media->tags()->sync($data['tag_ids']);
        }
        if (array_key_exists('collection_ids', $data)) {
            $media->collections()->sync($data['collection_ids']);
        }

        return response()->json($this->serialize($media));
    }

    public function destroy($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        $media = SnkKilkanaMediaModel::query()->find($id);
        if (! $media) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        $media->delete(); // soft delete → papelera

        return response()->json(['id' => (string) $id]);
    }

    /** Restaura una pieza de la papelera. */
    public function restore($id): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        $media = SnkKilkanaMediaModel::onlyTrashed()->find($id);
        if (! $media) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        $media->restore();

        return response()->json($this->serialize($media));
    }

    /** Borrado permanente: elimina el fichero del disco (si es propio) y la fila. */
    public function forceDestroy($id): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        $media = SnkKilkanaMediaModel::withTrashed()->find($id);
        if (! $media) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        if ($media->disk && $media->path) {
            Storage::disk($media->disk)->delete($media->path);
        }
        $media->forceDelete();

        return response()->json(['id' => (string) $id]);
    }

    /** Acciones en lote: papelera, restaurar, o asignar tags/colecciones. */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'action' => 'required|in:trash,restore,tag,collect',
            'tag_ids' => 'sometimes|array',
            'tag_ids.*' => 'integer',
            'collection_ids' => 'sometimes|array',
            'collection_ids.*' => 'integer',
        ]);

        $permission = in_array($data['action'], ['trash', 'restore'], true) ? $this->destroyPermission : $this->updatePermission;
        if ($response = $this->requirePermission($permission)) {
            return $response;
        }

        $ids = $data['ids'];
        switch ($data['action']) {
            case 'trash':
                SnkKilkanaMediaModel::query()->whereIn('id', $ids)->delete();
                break;
            case 'restore':
                SnkKilkanaMediaModel::onlyTrashed()->whereIn('id', $ids)->restore();
                break;
            case 'tag':
                foreach (SnkKilkanaMediaModel::query()->whereIn('id', $ids)->get() as $media) {
                    $media->tags()->syncWithoutDetaching($data['tag_ids'] ?? []);
                }
                break;
            case 'collect':
                foreach (SnkKilkanaMediaModel::query()->whereIn('id', $ids)->get() as $media) {
                    $media->collections()->syncWithoutDetaching($data['collection_ids'] ?? []);
                }
                break;
        }

        return response()->json(['ok' => true, 'count' => count($ids)]);
    }

    /**
     * Sube un fichero al disco del tenant (carpeta por BD del par cliente-paquete).
     * Dedup por hash de contenido: un fichero idéntico ya subido devuelve 409.
     * Las dimensiones/duración las manda el cliente (metadatos nativos del navegador);
     * para imágenes se prefiere getimagesize del servidor si está disponible.
     */
    public function upload(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->storePermission)) {
            return $response;
        }

        $data = $request->validate([
            'file' => 'required|file|mimes:'.self::MIMES.'|max:51200', // 50 MB
            'width' => 'nullable|integer|min:0',
            'height' => 'nullable|integer|min:0',
            'duration' => 'nullable|integer|min:0',
        ]);

        $file = $data['file'];
        $hash = hash_file('sha256', $file->getRealPath());

        $existing = SnkKilkanaMediaModel::withTrashed()->where('content_hash', $hash)->first();
        if ($existing) {
            return response()->json(['error' => 'Duplicate', 'media' => $this->serialize($existing)], 409);
        }

        $dir = 'media/'.DB::connection()->getDatabaseName();
        $path = $file->store($dir, 'public');
        [$width, $height] = $this->dimensions($file->getRealPath(), $data);

        $media = SnkKilkanaMediaModel::create([
            'url' => '/storage/'.$path,
            'original_name' => $file->getClientOriginalName(),
            'title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'mime' => $file->getMimeType(),
            'width' => $width,
            'height' => $height,
            'duration' => $data['duration'] ?? null,
            'size_bytes' => $file->getSize(),
            'content_hash' => $hash,
            'disk' => 'public',
            'path' => $path,
            'uploaded_by' => auth()->id(),
        ]);

        return response()->json($this->serialize($media), 201);
    }

    /** Reemplaza el binario conservando los metadatos editoriales y el id. */
    public function replace(Request $request, $id): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }

        $media = SnkKilkanaMediaModel::query()->find($id);
        if (! $media) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $data = $request->validate([
            'file' => 'required|file|mimes:'.self::MIMES.'|max:51200',
            'width' => 'nullable|integer|min:0',
            'height' => 'nullable|integer|min:0',
            'duration' => 'nullable|integer|min:0',
        ]);

        $file = $data['file'];
        $hash = hash_file('sha256', $file->getRealPath());

        $clash = SnkKilkanaMediaModel::withTrashed()->where('content_hash', $hash)->where('id', '!=', $media->id)->first();
        if ($clash) {
            return response()->json(['error' => 'Duplicate', 'media' => $this->serialize($clash)], 409);
        }

        if ($media->disk && $media->path) {
            Storage::disk($media->disk)->delete($media->path);
        }
        $dir = 'media/'.DB::connection()->getDatabaseName();
        $path = $file->store($dir, 'public');
        [$width, $height] = $this->dimensions($file->getRealPath(), $data);

        $media->update([
            'url' => '/storage/'.$path,
            'mime' => $file->getMimeType(),
            'width' => $width,
            'height' => $height,
            'duration' => $data['duration'] ?? null,
            'size_bytes' => $file->getSize(),
            'content_hash' => $hash,
            'disk' => 'public',
            'path' => $path,
        ]);

        return response()->json($this->serialize($media));
    }

    /** Dimensiones: getimagesize del servidor (imágenes) o las que manda el cliente. */
    private function dimensions(string $realPath, array $data): array
    {
        $size = @getimagesize($realPath);
        if ($size) {
            return [$size[0], $size[1]];
        }

        return [$data['width'] ?? null, $data['height'] ?? null];
    }

    /** Query base con relaciones, conteo de uso y todos los filtros del frontend. */
    private function filtered(Request $request): Builder
    {
        $query = SnkKilkanaMediaModel::query()
            ->with(['tags', 'collections'])
            ->withCount('coveredPosts');

        $status = $request->string('status')->toString();
        if ($status === 'trash') {
            $query->onlyTrashed();
        } elseif ($status === 'review') {
            $query->where('rights_status', 'unknown');
        } elseif ($status === 'unused') {
            $query->whereDoesntHave('coveredPosts');
        }

        if ($q = $request->string('q')->trim()->toString()) {
            $query->where(function (Builder $sub) use ($q) {
                foreach (['title', 'original_name', 'alt', 'caption', 'credit', 'description'] as $column) {
                    $sub->orWhere($column, 'like', "%{$q}%");
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

    /** Contadores globales de las pestañas (no dependen de los filtros activos). */
    private function facets(): array
    {
        $soon = Carbon::today()->addDays(30)->toDateString();

        return [
            'all' => SnkKilkanaMediaModel::query()->count(),
            'review' => SnkKilkanaMediaModel::query()->where('rights_status', 'unknown')->count(),
            // «Sin usar» es una vista como review o trash, no un filtro: lleva su contador.
            'unused' => SnkKilkanaMediaModel::query()->whereDoesntHave('coveredPosts')->count(),
            'missing_alt' => SnkKilkanaMediaModel::query()->where(fn (Builder $q) => $q->whereNull('alt')->orWhere('alt', ''))->count(),
            'expiring' => SnkKilkanaMediaModel::query()->whereNotNull('rights_expires_at')->whereDate('rights_expires_at', '<=', $soon)->count(),
            'trash' => SnkKilkanaMediaModel::onlyTrashed()->count(),
        ];
    }

    /** Recarga con relaciones + conteo para que la respuesta cuadre con el contrato del index. */
    private function serialize(SnkKilkanaMediaModel $media): SnkKilkanaMediaModel
    {
        return $media->fresh(['tags', 'collections'])->loadCount('coveredPosts');
    }
}
