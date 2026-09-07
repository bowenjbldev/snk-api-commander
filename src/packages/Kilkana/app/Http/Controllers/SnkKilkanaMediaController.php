<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Kilkana\Enums\SnkKilkanaMediaRightsEnum;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Services\SnkKilkanaImageService;
use Packages\Kilkana\Services\SnkKilkanaMediaService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Biblioteca de medios estilo WordPress: subida al disco del tenant con dedup por
 * hash de contenido, metadatos editoriales y de derechos, taxonomías (tags +
 * colecciones) y papelera (soft delete). El index sirve {data, meta, facets, scope}: la
 * lista paginada y filtrada, los contadores globales del archivo y los de lo que se está
 * mirando, que son los que llevan las pestañas.
 */
#[Group('Media')]
class SnkKilkanaMediaController extends CoreBaseApiController
{
    public function __construct(private readonly SnkKilkanaMediaService $media, private readonly SnkKilkanaImageService $images) {}

    protected string $modelClass = SnkKilkanaMediaModel::class;

    protected string $indexPermission = 'index media';

    protected string $showPermission = 'show media';

    protected string $storePermission = 'create media';

    protected string $updatePermission = 'update media';

    protected string $destroyPermission = 'delete media';

    /** Mimes aceptados por el contrato de subida y reemplazo. */
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

    /**
     * La ficha, con **quien la usa por su nombre**.
     *
     * El listado ya trae `usage_count`, que es un numero; el inspector del panel ensena la
     * lista —«Used in», con los titulares—, y ese dato es justo por el que se entra a una
     * ficha antes de borrarla. Un contador a 3 no dice si esos 3 son borradores viejos o la
     * portada de hoy.
     *
     * Va en `show` y no en el listado a proposito: son tres consultas por fila, y multiplicarlas
     * por las veinticinco de una pagina para pintar un numero que ya viene contado es pagar
     * setenta y cinco consultas por nada.
     */
    public function show($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->showPermission)) {
            return $response;
        }

        $media = $this->media->findWithTrashed($id);
        if (! $media) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        return response()->json([
            ...$this->media->serialize($media)->toArray(),
            'uses' => $this->media->uses($media),
        ]);
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
            'rights_status' => 'sometimes|string|'.SnkKilkanaMediaRightsEnum::rule(),
            'license' => 'sometimes|nullable|string|max:500',
            'rights_expires_at' => 'sometimes|nullable|date',
            'focal_x' => 'sometimes|numeric|between:0,1',
            'focal_y' => 'sometimes|numeric|between:0,1',
            // El marco: de donde salen las derivadas, en fracciones del original. Ancho y
            // alto empiezan en 0.0001 y no en 0 — un marco de area cero no acota nada, es un
            // recorte que deja la foto en nada y no hay forma de deshacerlo desde el panel.
            'crop_x' => 'sometimes|numeric|between:0,1',
            'crop_y' => 'sometimes|numeric|between:0,1',
            'crop_w' => 'sometimes|numeric|between:0.0001,1',
            'crop_h' => 'sometimes|numeric|between:0.0001,1',
            // El encuadre propio de una proporción, en fracciones del master; las claves que no
            // sean una proporción de la fabrica se descartan en el servicio.
            'frames' => 'sometimes|nullable|array',
            'frames.*' => 'array',
            'frames.*.x' => 'required|numeric|between:0,1',
            'frames.*.y' => 'required|numeric|between:0,1',
            'frames.*.w' => 'required|numeric|between:0.0001,1',
            'frames.*.h' => 'required|numeric|between:0.0001,1',
            // `exists` y no solo `integer`: el sync mete la fila en el pivote, así que un id
            // inventado chocaba contra la clave foránea y salía como 500.
            'tag_ids' => 'sometimes|array',
            'tag_ids.*' => 'integer|exists:kilkana_media_tags,id',
            'collection_ids' => 'sometimes|array',
            'collection_ids.*' => 'integer|exists:kilkana_media_collections,id',
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
            // Sin `exists` no reventaba nada, pero la respuesta mentía: contaba los ids
            // pedidos, no los tocados, así que un id inventado devolvía «hecho» sin hacerlo.
            // La regla no filtra por borrado lógico, así que `restore` sigue funcionando.
            'ids.*' => 'integer|exists:kilkana_media,id',
            'action' => 'required|in:trash,restore,tag,untag,collect,uncollect',
            // `exists` y no solo `integer`: el sync mete la fila en el pivote, así que un id
            // inventado chocaba contra la clave foránea y salía como 500.
            'tag_ids' => 'sometimes|array',
            'tag_ids.*' => 'integer|exists:kilkana_media_tags,id',
            'collection_ids' => 'sometimes|array',
            'collection_ids.*' => 'integer|exists:kilkana_media_collections,id',
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
    /** La miniatura de un segundo del vídeo, para elegir la portada. */
    public function frame($id, int $at): BinaryFileResponse|JsonResponse
    {
        if ($response = $this->requirePermission($this->showPermission)) {
            return $response;
        }
        $media = $this->media->findWithTrashed($id);
        if (! $media) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        $path = $this->images->frame($media, $at);
        if ($path === null) {
            return response()->json(['error' => 'No frame there'], 404);
        }

        return response()->file(Storage::disk('public')->path($path), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** Elegir la portada de un vídeo: el segundo pasa a ser el máster. */
    public function poster(Request $request, $id): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }
        $media = $this->media->find($id);
        if (! $media) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        // O un segundo del vídeo, o una foto de la biblioteca: exactamente uno de los dos.
        $data = $request->validate([
            'at' => 'required_without:media_id|prohibits:media_id|integer|min:0|max:'.max(0, (int) $media->duration),
            'media_id' => 'required_without:at|integer|exists:kilkana_media,id',
        ]);
        if (isset($data['media_id'])) {
            $photo = $this->media->find($data['media_id']);
            $row = $photo && $this->images->isImage($photo->mime) ? $this->media->posterFrom($media, $photo) : null;
        } else {
            $row = $this->media->poster($media, (int) $data['at']);
        }

        return $row === null
            ? response()->json(['error' => 'That file cannot be the poster'], 422)
            : response()->json($row);
    }

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
