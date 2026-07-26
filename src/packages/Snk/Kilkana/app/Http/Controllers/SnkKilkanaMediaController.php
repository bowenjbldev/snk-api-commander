<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Snk\Kilkana\Services\SnkKilkanaMediaService;

/**
 * Biblioteca de medios estilo WordPress: subida al disco del tenant con dedup por
 * hash de contenido, metadatos editoriales y de derechos, taxonomías (tags +
 * colecciones) y papelera (soft delete). El index sirve {data, meta, facets}: la
 * lista paginada y filtrada + los contadores globales de las pestañas.
 */
#[Group('Media')]
class SnkKilkanaMediaController extends CoreBaseApiController
{
    public function __construct(private readonly SnkKilkanaMediaService $media) {}

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

        return response()->json($this->media->index(request()));
    }

    public function update(Request $request, $id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }

        $media = $this->media->find($id);
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

        return response()->json($this->media->update($media, $data));
    }

    public function destroy($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        $media = $this->media->find($id);
        if (! $media) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        $this->media->trash($media);

        return response()->json(['id' => (string) $id]);
    }

    /** Restaura una pieza de la papelera. */
    public function restore($id): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        $media = $this->media->findTrashed($id);
        if (! $media) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        return response()->json($this->media->restore($media));
    }

    /** Borrado permanente: elimina el fichero del disco (si es propio) y la fila. */
    public function forceDestroy($id): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }

        $media = $this->media->findWithTrashed($id);
        if (! $media) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        $this->media->forceDestroy($media);

        return response()->json(['id' => (string) $id]);
    }

    /** Acciones en lote: papelera, restaurar, o asignar tags/colecciones. */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'action' => 'required|in:trash,restore,tag,untag,collect,uncollect',
            'tag_ids' => 'sometimes|array',
            'tag_ids.*' => 'integer',
            'collection_ids' => 'sometimes|array',
            'collection_ids.*' => 'integer',
        ]);

        $permission = in_array($data['action'], ['trash', 'restore'], true) ? $this->destroyPermission : $this->updatePermission;
        if ($response = $this->requirePermission($permission)) {
            return $response;
        }

        $this->media->bulk(
            $data['ids'],
            $data['action'],
            $data['tag_ids'] ?? [],
            $data['collection_ids'] ?? [],
        );

        return response()->json(['ok' => true, 'count' => count($data['ids'])]);
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

        $result = $this->media->upload($data, (int) auth()->id());

        return $result['duplicate']
            ? response()->json(['error' => 'Duplicate', 'media' => $result['media']], 409)
            : response()->json($result['media'], 201);
    }

    /** Reemplaza el binario conservando los metadatos editoriales y el id. */
    public function replace(Request $request, $id): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }

        $media = $this->media->find($id);
        if (! $media) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $data = $request->validate([
            'file' => 'required|file|mimes:'.self::MIMES.'|max:51200',
            'width' => 'nullable|integer|min:0',
            'height' => 'nullable|integer|min:0',
            'duration' => 'nullable|integer|min:0',
        ]);

        $result = $this->media->replace($media, $data);

        return $result['duplicate']
            ? response()->json(['error' => 'Duplicate', 'media' => $result['media']], 409)
            : response()->json($result['media']);
    }
}
