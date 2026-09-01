<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Enums\SnkKilkanaCommentStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Kilkana\Services\SnkKilkanaCommentService;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;

/**
 * Comentarios de lectores registrados. `store` lo usa cualquier usuario con
 * `create comments` (rol reader): el autor es siempre el del token y el estado
 * nace `pending`. Lo demas es moderacion: aprobar, rechazar, marcar spam, borrar
 * o cerrarle la puerta a quien lo escribio.
 */
#[Group('Comments')]
class SnkKilkanaCommentsController extends CoreBaseApiController
{
    private const PER_PAGE = 25;

    private const PER_PAGE_MAX = 100;

    /** `rule()` es una llamada, y una propiedad no se inicializa con eso: va aqui. */
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
                'counts' => $this->countsByStatus(),
                'oldest_pending_at' => $this->oldestPendingAt(),
            ]);
        }

        $status = SnkKilkanaCommentStatusEnum::tryFrom((string) $request->query('status', ''));
        $term = trim((string) $request->query('q', ''));
        $term = mb_strlen($term) >= 2 ? mb_substr($term, 0, 128) : '';
        $asked = (int) $request->query('per_page', (string) self::PER_PAGE);
        $perPage = $asked < 1 ? self::PER_PAGE : min($asked, self::PER_PAGE_MAX);

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
            ->paginate($perPage);

        $historial = $this->accountHistory($page->getCollection());
        $page->getCollection()->each(fn (SnkKilkanaCommentModel $c) => $this->asRow($c, $historial));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'counts' => $this->countsByStatus(),
            'oldest_pending_at' => $this->oldestPendingAt(),
            'blocked_readers' => CoreUserModel::query()
                ->whereNotNull('suspended_at')
                ->whereHas('roles', fn (Builder $r) => $r->where('name', 'reader')->where('guard_name', 'sanctum'))
                ->count(),
        ]);
    }

    /** Los conteos del periodico entero, por estado y en total: las pestañas dicen cuanto queda. */
    private function countsByStatus(): array
    {
        $porEstado = SnkKilkanaCommentModel::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $counts = [];
        foreach (SnkKilkanaCommentStatusEnum::cases() as $caso) {
            $counts[$caso->value] = (int) $porEstado->get($caso->value, 0);
        }
        $counts['all'] = array_sum($counts);

        return $counts;
    }

    private function oldestPendingAt(): ?string
    {
        return SnkKilkanaCommentModel::query()
            ->where('status', SnkKilkanaCommentStatusEnum::Pending)
            ->min('created_at');
    }

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

        return response()->json([
            'ok' => true,
            'count' => $this->comments->bulk($data['ids'], $data['action']),
        ]);
    }

    /**
     * Cierra la puerta a quien escribio el comentario, y rechaza lo que tenia en la cola.
     *
     * Comentar exige cuenta, asi que bloquear es suspender esa cuenta: tira sus sesiones y el
     * login le dice que no. Solo a un lector: a un companero no se le bloquea desde la
     * moderacion, eso es cosa de cuentas, y una cuenta dada de baja ya no esta. Deshacerlo es
     * de la pantalla de lectores, no de esta.
     */
    public function blockAuthor($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }

        $comentario = $this->newQuery()->with('user')->find($id);
        if ($comentario === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        // La pregunta no es «¿es lector?» sino «¿es SOLO lector?»: Spatie admite varios roles, y
        // quien tenga ademas un asiento es un companero, aunque tambien comente. Falla cerrado.
        $autor = $comentario->user;
        $roles = $autor?->roles->pluck('name') ?? collect();
        $soloLector = $roles->contains('reader')
            && $roles->every(fn (string $rol) => in_array($rol, SnkKilkanaRoleCatalogService::WITHOUT_SEAT, true));
        if ($autor === null || ! $soloLector) {
            return response()->json(['error' => 'Not a reader account'], 409);
        }

        $autor->forceFill(['suspended_at' => now()])->save();
        $autor->tokens()->delete();
        $rechazados = SnkKilkanaCommentModel::query()
            ->where('user_id', $autor->getKey())
            ->where('status', SnkKilkanaCommentStatusEnum::Pending)
            ->update(['status' => SnkKilkanaCommentStatusEnum::Rejected]);

        return response()->json(['blocked' => (string) $autor->getKey(), 'rejected' => $rechazados]);
    }

    /**
     * Cuantos ha escrito y cuantos se le han rechazado, por cuenta, en dos consultas para la
     * pagina entera y no dos por fila.
     *
     * @return array<int, array{total: int, rejected: int}>
     */
    private function accountHistory(Collection $comentarios): array
    {
        $ids = $comentarios->pluck('user_id')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $historial = [];
        SnkKilkanaCommentModel::query()
            ->whereIn('user_id', $ids)
            ->selectRaw('user_id, status, count(*) as total')
            ->groupBy('user_id', 'status')
            ->get()
            ->each(function ($fila) use (&$historial) {
                $historial[$fila->user_id] ??= ['total' => 0, 'rejected' => 0];
                $historial[$fila->user_id]['total'] += (int) $fila->total;
                if ($fila->status === SnkKilkanaCommentStatusEnum::Rejected) {
                    $historial[$fila->user_id]['rejected'] += (int) $fila->total;
                }
            });

        return $historial;
    }

    /** La fila tal como la lee el panel: la cuenta, la pieza y el padre ya resueltos. */
    private function asRow(SnkKilkanaCommentModel $c, array $historial): void
    {
        $user = $c->user;
        $c->setAttribute('author', $user === null ? null : [
            'id' => (string) $user->getKey(),
            'name' => $user->full_name ?: $user->name,
            'email' => $user->email,
            'since' => $user->created_at?->toIso8601String(),
            'total' => $historial[$user->getKey()]['total'] ?? 0,
            'rejected' => $historial[$user->getKey()]['rejected'] ?? 0,
            'blocked' => $user->suspended_at !== null,
        ]);
        $c->setAttribute('on', $c->post === null ? null : ['id' => (string) $c->post->getKey(), 'title' => $c->post->title]);
        $c->setAttribute('reply_to', $c->parent === null ? null : [
            'author' => $c->parent->user?->full_name ?: ($c->parent->user?->name ?? null),
            'body' => $c->parent->body,
        ]);
        $c->unsetRelation('user')->unsetRelation('post')->unsetRelation('parent');
    }
}
