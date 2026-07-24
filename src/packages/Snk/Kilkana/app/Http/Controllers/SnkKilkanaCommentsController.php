<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Snk\Kilkana\Models\SnkKilkanaCommentModel;

/**
 * Comentarios de lectores registrados. `store` lo usa cualquier usuario con
 * `create comments` (rol reader): el autor es siempre el del token y el estado
 * nace `pending`. index/update/destroy son moderación (rol admin): aprobar,
 * marcar spam o borrar.
 */
#[Group('Comments')]
class SnkKilkanaCommentsController extends CoreBaseApiController
{
    protected string $modelClass = SnkKilkanaCommentModel::class;

    protected string $indexPermission = 'index comments';

    protected string $showPermission = 'show comments';

    protected string $storePermission = 'create comments';

    protected string $updatePermission = 'update comments';

    protected string $destroyPermission = 'delete comments';

    // Moderación: solo el estado; el cuerpo y la autoría no se reescriben.
    protected array $validationRulesUpdate = [
        'status' => 'required|in:pending,approved,spam',
    ];

    protected array $allowedWith = ['user', 'post', 'replies'];

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->storePermission)) {
            return $response;
        }

        $data = $request->validate([
            'post_id' => 'required|integer|exists:kilkana_posts,id',
            'parent_id' => 'nullable|integer|exists:kilkana_comments,id',
            'body' => 'required|string|max:5000',
        ]);

        // Una respuesta cuelga de un comentario del MISMO artículo.
        if (! empty($data['parent_id'])) {
            $parentPost = SnkKilkanaCommentModel::query()->whereKey($data['parent_id'])->value('post_id');
            if ((int) $parentPost !== (int) $data['post_id']) {
                return response()->json(['error' => 'Parent comment belongs to another post'], 422);
            }
        }

        $comment = SnkKilkanaCommentModel::create([
            ...$data,
            'user_id' => auth()->id(),
            'status' => 'pending',
        ]);

        return response()->json($comment, 201);
    }

    /**
     * Moderación en masa desde el panel; mismo contrato que `posts/bulk`.
     * Borrar arrastra el hilo: parent_id es cascadeOnDelete.
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'action' => 'required|in:approve,pending,spam,delete',
        ]);

        $permission = $data['action'] === 'delete' ? $this->destroyPermission : $this->updatePermission;
        if ($response = $this->requirePermission($permission)) {
            return $response;
        }

        $query = SnkKilkanaCommentModel::query()->whereIn('id', $data['ids']);

        if ($data['action'] === 'delete') {
            $count = $query->count();
            $query->delete();

            return response()->json(['ok' => true, 'count' => $count]);
        }

        $count = $query->update([
            'status' => $data['action'] === 'approve' ? 'approved' : $data['action'],
        ]);

        return response()->json(['ok' => true, 'count' => $count]);
    }
}
