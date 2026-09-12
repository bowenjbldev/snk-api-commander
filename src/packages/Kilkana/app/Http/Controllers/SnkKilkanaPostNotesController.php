<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Kilkana\Http\Resources\SnkKilkanaPostNoteResource;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaPostNoteModel;

/**
 * Las notas de mesa de una pieza.
 *
 * Se leen con la llave de leer piezas y se escriben con la de editarlas: una nota es parte del
 * trabajo sobre el artículo, no una conversación aparte. No se borran: se resuelven, y así el
 * hilo de dudas de una pieza se queda como historia de cómo se cerró.
 */
#[Group('Posts')]
class SnkKilkanaPostNotesController extends CoreBaseApiController
{
    protected string $modelClass = SnkKilkanaPostNoteModel::class;

    protected string $showPermission = 'show posts';

    protected string $updatePermission = 'update posts';

    public function list($postId): JsonResponse
    {
        if ($response = $this->requirePermission($this->showPermission)) {
            return $response;
        }
        if (! SnkKilkanaPostModel::query()->whereKey($postId)->exists()) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        return response()->json(SnkKilkanaPostNoteResource::collection(SnkKilkanaPostNoteModel::query()
            ->where('post_id', $postId)
            ->with('user:id,name,lastname')
            ->orderByDesc('created_at')
            ->get()));
    }

    public function add(Request $request, $postId): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }
        if (! SnkKilkanaPostModel::query()->whereKey($postId)->exists()) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        $data = $request->validate([
            'body' => 'required|string|max:2000',
            'block' => 'nullable|string|max:128',
        ]);

        $note = SnkKilkanaPostNoteModel::create([
            'post_id' => (int) $postId,
            'user_id' => (int) auth()->id(),
            'block' => $data['block'] ?? null,
            'body' => $data['body'],
        ]);

        return response()->json(new SnkKilkanaPostNoteResource($note->load('user:id,name,lastname')), 201);
    }

    /** Resolver o reabrir: lo único que cambia de una nota después de escrita. */
    public function resolve(Request $request, $postId, $noteId): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }
        $note = SnkKilkanaPostNoteModel::query()->whereKey($noteId)->where('post_id', $postId)->first();
        if ($note === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        $data = $request->validate(['done' => 'required|boolean']);
        $note->update(['done' => $data['done']]);

        return response()->json(new SnkKilkanaPostNoteResource($note->load('user:id,name,lastname')));
    }
}
