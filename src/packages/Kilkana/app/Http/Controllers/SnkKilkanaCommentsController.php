<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Kilkana\Enums\SnkKilkanaCommentStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Kilkana\Services\SnkKilkanaCommentService;

/**
 * Comentarios de lectores registrados. `store` lo usa cualquier usuario con
 * `create comments` (rol reader): el autor es siempre el del token y el estado
 * nace `pending`. index/update/destroy son moderación (rol admin): aprobar,
 * marcar spam o borrar.
 */
#[Group('Comments')]
class SnkKilkanaCommentsController extends CoreBaseApiController
{
    /** `rule()` es una llamada, y una propiedad no se inicializa con eso: va aquí. */
    public function __construct(private readonly SnkKilkanaCommentService $comments)
    {
        $this->validationRulesUpdate['status'] = 'required|'.SnkKilkanaCommentStatusEnum::rule();
    }

    protected string $modelClass = SnkKilkanaCommentModel::class;

    protected string $indexPermission = 'index comments';

    protected string $showPermission = 'show comments';

    protected string $storePermission = 'create comments';

    protected string $updatePermission = 'update comments';

    protected string $destroyPermission = 'delete comments';

    // Moderación: solo el estado; el cuerpo y la autoría no se reescriben.
    protected array $validationRulesUpdate = [];

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

        $comment = $this->comments->create($data, (int) auth()->id());
        if ($comment === null) {
            return response()->json(['error' => 'Parent comment belongs to another post'], 422);
        }

        return response()->json($comment, 201);
    }

    /**
     * Moderación en masa; mismo contrato que `posts/bulk`.
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

        return response()->json([
            'ok' => true,
            'count' => $this->comments->bulk($data['ids'], $data['action']),
        ]);
    }
}
