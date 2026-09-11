<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Enums\SnkKilkanaCommentStatusEnum;
use Packages\Kilkana\Http\Resources\SnkKilkanaCommentResource;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Kilkana\Services\SnkKilkanaActivityService;
use Packages\Kilkana\Services\SnkKilkanaCommentService;
use Packages\Kilkana\Services\SnkKilkanaReaderService;

/**
 * Comentarios de lectores registrados. `store` lo usa cualquier usuario con
 * `create comments` (rol reader): el autor es siempre el del token y el estado
 * nace `pending`. Lo demas es moderacion: aprobar, rechazar, marcar spam, borrar
 * o cerrarle la puerta a quien lo escribio.
 */
#[Group('Comments')]
class SnkKilkanaCommentsController extends CoreBaseApiController
{
    /** `rule()` es una llamada, y una propiedad no se inicializa con eso: va aqui. */
    public function __construct(
        private readonly SnkKilkanaCommentService $comments,
        private readonly SnkKilkanaReaderService $lectores,
        private readonly SnkKilkanaActivityService $registro,
    ) {
        $this->validationRulesUpdate['status'] = 'required|'.SnkKilkanaCommentStatusEnum::rule();
    }

    protected string $modelClass = SnkKilkanaCommentModel::class;

    /**
     * `counts` es la salud de la cola —cuántos de cada estado y desde cuándo espera el más
     * viejo—, y `blocked_readers` una cifra de la banda. Las dos cuentan la tabla entera.
     */
    protected array $allowedSummary = ['counts', 'blocked_readers'];

    protected string $indexPermission = 'index comments';

    protected string $showPermission = 'show comments';

    protected string $storePermission = 'create comments';

    protected string $updatePermission = 'update comments';

    protected string $destroyPermission = 'delete comments';

    // Moderación: solo el estado; el cuerpo y la autoría no se reescriben.
    protected array $validationRulesUpdate = [];

    protected array $allowedIncludes = ['user', 'post', 'replies'];

    /**
     * La cola de moderacion: filtrada, paginada y con lo que hace falta para decidir sin abrir
     * nada mas.
     *
     * Cada fila lleva la pieza, el comentario al que responde —una respuesta no se puede
     * moderar sin la frase de arriba— y la cuenta con su historial: cuantos ha escrito y
     * cuantos se le han rechazado, que es lo que separa un comentario desafortunado de una
     * cuenta que lleva seis. Los conteos por estado son del periodico entero: las pestañas
     * tienen que decir cuanto queda aunque se este mirando otra.
     */
    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        $request = request();

        // Solo la cifra: el rail del panel pregunta cuantos esperan en cada carga del armazon,
        // y contestarle con la pagina entera son diez consultas para un entero.
        if ($request->boolean('counts')) {
            return response()->json([
                'counts' => $this->comments->countsByStatus(),
                'oldest_pending_at' => $this->comments->oldestPendingAt(),
            ]);
        }

        $status = SnkKilkanaCommentStatusEnum::tryFrom((string) $request->query('status', ''));
        $term = trim((string) $request->query('q', ''));
        $term = mb_strlen($term) >= 2 ? mb_substr($term, 0, 128) : '';

        $page = $this->newQuery()
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('body', 'like', "%{$term}%")
                ->orWhereHas('user', fn (Builder $u) => $u
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('full_name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"))))
            ->with([
                'user:id,name,full_name,email,created_at,suspended_at',
                'post:id,title',
                'parent:id,body,user_id',
                'parent.user:id,name,full_name',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage());

        $this->comments->withAuthorHistory($page->getCollection());

        return response()->json([
            'data' => SnkKilkanaCommentResource::collection($page->getCollection()),
            'meta' => $this->paginationMeta($page),
            ...$this->wants('counts') ? [
                'counts' => $this->comments->countsByStatus(),
                'oldest_pending_at' => $this->comments->oldestPendingAt(),
            ] : [],
            ...$this->wants('blocked_readers') ? [
                'blocked_readers' => CoreUserModel::query()
                    ->whereNotNull('suspended_at')
                    ->whereHas('roles', fn (Builder $r) => $r->where('name', 'reader')->where('guard_name', 'sanctum'))
                    ->count(),
            ] : [],
        ]);
    }

    /** Los conteos del periodico entero, por estado y en total: las pestañas dicen cuanto queda. */
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
            'action' => 'required|in:'.implode(',', [...array_keys(SnkKilkanaCommentService::STATUS_OF), 'delete']),
        ]);

        $permission = $data['action'] === 'delete' ? $this->destroyPermission : $this->updatePermission;
        if ($response = $this->requirePermission($permission)) {
            return $response;
        }

        // Sobre qué, antes de mover nada: borrar se lleva las filas que lo dirían.
        $piezas = SnkKilkanaCommentModel::query()->whereIn('kilkana_comments.id', $data['ids'])
            ->join('kilkana_posts', 'kilkana_posts.id', '=', 'kilkana_comments.post_id')
            ->distinct()->pluck('kilkana_posts.title');
        $count = $this->comments->bulk($data['ids'], $data['action']);
        if ($count > 0) {
            $verbo = match ($data['action']) {
                'approve' => 'comments_approved',
                'reject' => 'comments_rejected',
                'spam' => 'comments_spammed',
                'pending' => 'comments_reopened',
                default => 'comments_deleted',
            };
            // Sobre una pieza se nombra; sobre varias se cuentan, y la frase la pone el panel.
            $una = $piezas->count() === 1;
            $this->registro->log('comment', $verbo, $una ? (string) $piezas->first() : '', '/comments', [
                'params' => $una ? ['count' => $count] : ['count' => $count, 'posts' => $piezas->count()],
            ]);
        }

        return response()->json(['ok' => true, 'count' => $count]);
    }

    /**
     * Cierra la puerta a quien escribio el comentario, y rechaza lo que tenia en la cola.
     *
     * Comentar exige cuenta, asi que bloquear es suspender esa cuenta: lo hace el servicio
     * de lectores, el mismo que usa su pantalla, y con SU permiso: bloquear a una persona
     * no es moderar una frase, y quien modera sin `update readers` no bloquea desde aqui.
     * Solo a un lector: a un companero no se le bloquea desde la moderacion, eso es cosa de
     * cuentas, y una cuenta dada de baja ya no esta. Deshacerlo es de la pantalla de lectores.
     */
    public function blockAuthor($id = null): JsonResponse
    {
        if ($response = $this->requirePermission('update readers')) {
            return $response;
        }

        $comentario = $this->newQuery()->with('user.roles')->find($id);
        if ($comentario === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $autor = $comentario->user;
        if (! $this->lectores->isReader($autor)) {
            return response()->json(['error' => 'Not a reader account'], 409);
        }

        return response()->json(['blocked' => (string) $autor->getKey(), 'rejected' => $this->lectores->block($autor)]);
    }
}
