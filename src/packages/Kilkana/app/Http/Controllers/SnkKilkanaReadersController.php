<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Enums\SnkKilkanaCommentStatusEnum;
use Packages\Kilkana\Enums\SnkKilkanaSubscriberStatusEnum;
use Packages\Kilkana\Http\Resources\SnkKilkanaReaderResource;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Kilkana\Models\SnkKilkanaSubscriberModel;
use Packages\Kilkana\Services\SnkKilkanaReaderService;

/**
 * Las cuentas lectoras: quien comenta, quien recibe el boletín y quien consume piezas medidas.
 *
 * Es una sola tabla porque es una sola persona, y **una cuenta, no un perfil**: no se borra
 * desde aquí —se llevaría sus comentarios— y lo que se ofrece es bloquear, que se deshace.
 * Solo cuentas con el papel `reader` y ningún asiento: la redacción vive en `users`.
 */
#[Group('Readers')]
class SnkKilkanaReadersController extends CoreBaseApiController
{
    /** El techo de un export: lo filtrado entero, sin paginar, hasta aquí. */
    private const EXPORT_MAX = 5000;

    public const TABS = ['all', 'subscribers', 'commenters', 'blocked'];

    public function __construct(private readonly SnkKilkanaReaderService $readers) {}

    protected string $modelClass = CoreUserModel::class;

    /** Las cifras de las pestañas y la banda de la cabecera: cuentan el archivo, no la página. */
    protected array $allowedSummary = ['counts', 'stats'];

    protected string $indexPermission = 'index readers';

    protected string $updatePermission = 'update readers';

    protected string $destroyPermission = 'delete readers';

    /**
     * La lista filtrada y paginada, con las cifras de las pestañas y de la banda.
     *
     * Las pestañas no son estados: son tres preguntas distintas sobre la misma persona
     * —¿recibe el boletín?, ¿comenta?, ¿está bloqueada?— y alguien cae en las tres. La única
     * regla que las cruza: un bloqueado no cuenta como comentarista, porque esa pestaña es
     * con quién se puede contar. `summary=counts,stats` añade las pestañas y la banda —que
     * cuentan la tabla, no la página, así que no viajan si no se piden—; `counts=1` devuelve
     * solo las cifras y nada más; `all=1`, lo filtrado
     * entero sin paginar, que es lo que se lleva un export.
     */
    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        $request = request();
        // Solo la cifra del rail: cuantas cuentas hay, en una consulta. Las pestañas y la banda
        // son ocho, y el armazon las pediria en cada carga para pintar un entero.
        if ($request->boolean('counts')) {
            return response()->json(['accounts' => $this->readers()->count()]);
        }

        $tab = (string) $request->query('tab', 'all');
        $tab = in_array($tab, self::TABS, true) ? $tab : 'all';
        $term = trim((string) $request->query('q', ''));
        $term = mb_strlen($term) >= 2 ? mb_substr($term, 0, 128) : '';

        $query = $this->readers()
            ->when($tab !== 'all', fn (Builder $q) => $this->onTab($q, $tab))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$term}%")
                ->orWhere('lastname', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")))
            ->withMax('tokens as last_token_at', 'last_used_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            $rows = $query->limit(self::EXPORT_MAX)->get();

            return response()->json(['data' => SnkKilkanaReaderResource::collection($this->readers->withActivity($rows))]);
        }

        $page = $query->paginate($this->perPage());
        $counts = $this->wants('counts') ? $this->counts() : null;

        return response()->json([
            'data' => SnkKilkanaReaderResource::collection($this->readers->withActivity($page->getCollection())),
            'meta' => $this->paginationMeta($page),
            // Las dos comparten dos consultas, así que se calculan en este orden y la banda
            // reutiliza lo que las pestañas ya contaron. Ver `stats()`.
            ...$counts === null ? [] : ['counts' => $counts],
            ...$this->wants('stats') ? ['stats' => $this->stats($counts)] : [],
        ]);
    }

    /** Cierra la puerta a una cuenta lectora. Deshacerlo es `unblock`. */
    public function block($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }
        $user = $this->reader($id);
        if ($user === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $rejected = $this->readers->block($user);

        return response()->json(['blocked' => (string) $user->getKey(), 'rejected' => $rejected]);
    }

    /** Retirar a un lector: lo que identifica desaparece; sus comentarios se quedan sin nombre. */
    public function anonymize($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->destroyPermission)) {
            return $response;
        }
        $user = $this->reader($id);
        if ($user === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $this->readers->anonymize($user);

        return response()->json(['anonymized' => (string) $user->getKey()]);
    }

    public function unblock($id = null): JsonResponse
    {
        if ($response = $this->requirePermission($this->updatePermission)) {
            return $response;
        }
        $user = $this->reader($id);
        if ($user === null) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        $this->readers->unblock($user);

        return response()->json(['unblocked' => (string) $user->getKey()]);
    }

    /** Solo las cuentas que son SOLO lectoras: la redacción no sale aquí ni con `id` a mano. */
    private function readers(): Builder
    {
        return $this->readers->accounts();
    }

    private function reader($id): ?CoreUserModel
    {
        return $this->readers()->whereKey($id)->first();
    }

    private function onTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'subscribers' => $query->whereIn('email', $this->confirmedEmails()),
            // `users` es de Core y no conoce los comentarios: la relación no existe, y no se le añade.
            // Sin el spam: la pestaña es con quién se puede contar, y alguien cuyo único comentario
            // es publicidad no es un comentarista, es la siguiente cuenta que bloquear.
            'commenters' => $query->whereNull('suspended_at')->whereIn('users.id', SnkKilkanaCommentModel::query()
                ->select('user_id')->distinct()
                ->whereNotNull('user_id')
                ->where('status', '<>', SnkKilkanaCommentStatusEnum::Spam)
                ->toBase()),
            'blocked' => $query->whereNotNull('suspended_at'),
            default => $query,
        };
    }

    private function confirmedEmails(): QueryBuilder
    {
        return SnkKilkanaSubscriberModel::query()
            ->where('status', SnkKilkanaSubscriberStatusEnum::Confirmed)
            ->select('email')
            ->toBase();
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach (self::TABS as $tab) {
            $counts[$tab] = $this->readers()->when($tab !== 'all', fn (Builder $q) => $this->onTab($q, $tab))->count();
        }

        return $counts;
    }

    /**
     * La banda: cuentas, la lista confirmada del boletín —que es más ancha que las cuentas:
     * se puede recibir el boletín sin cuenta—, quién ha comentado este mes y los bloqueados.
     *
     * @return array<string, int>
     */
    private function stats(?array $counts = null): array
    {
        return [
            // **`accounts` es `counts['all']` y `blocked` es `counts['blocked']`**: la misma
            // consulta escrita dos veces. La pantalla pide las dos cosas a la vez, así que se
            // contaban dos de más en cada carga, en cada página y en cada tecla del buscador.
            // Cuando las pestañas ya se han contado, la banda las reutiliza.
            'accounts' => $counts['all'] ?? $this->readers()->count(),
            'confirmed_subscribers' => SnkKilkanaSubscriberModel::query()->where('status', SnkKilkanaSubscriberStatusEnum::Confirmed)->count(),
            'commented_this_month' => SnkKilkanaCommentModel::query()
                ->where('created_at', '>=', now()->startOfMonth())
                ->whereIn('user_id', $this->readers()->select('users.id'))
                ->distinct()
                ->count('user_id'),
            'blocked' => $counts['blocked'] ?? $this->readers()->whereNotNull('suspended_at')->count(),
        ];
    }
}
