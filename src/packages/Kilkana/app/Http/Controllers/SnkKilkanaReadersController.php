<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Enums\SnkKilkanaCommentStatusEnum;
use Packages\Kilkana\Enums\SnkKilkanaSubscriberStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Kilkana\Models\SnkKilkanaReaderReadModel;
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
    private const PER_PAGE = 25;

    private const PER_PAGE_MAX = 100;

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
                ->orWhere('full_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")))
            ->withMax('tokens as last_token_at', 'last_used_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            $rows = $query->limit(self::EXPORT_MAX)->get();

            return response()->json(['data' => $this->asRows($rows)]);
        }

        $asked = (int) $request->query('per_page', (string) self::PER_PAGE);
        $page = $query->paginate($asked < 1 ? self::PER_PAGE : min($asked, self::PER_PAGE_MAX));
        $cifras = $this->wants('counts') ? $this->counts() : null;

        return response()->json([
            'data' => $this->asRows($page->getCollection()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            // Las dos comparten dos consultas, así que se calculan en este orden y la banda
            // reutiliza lo que las pestañas ya contaron. Ver `stats()`.
            ...$cifras === null ? [] : ['counts' => $cifras],
            ...$this->wants('stats') ? ['stats' => $this->stats($cifras)] : [],
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

        $rechazados = $this->readers->block($user);

        return response()->json(['blocked' => (string) $user->getKey(), 'rejected' => $rechazados]);
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

    /**
     * Las filas tal como las lee el panel, con lo que hace falta para la tabla y la ficha en
     * cuatro consultas para la página entera y no cuatro por fila: comentarios y rechazados,
     * lecturas medidas del mes, el boletín por correo y la última vez que se la vio.
     *
     * @return list<array<string, mixed>>
     */
    private function asRows(Collection $users): array
    {
        $ids = $users->modelKeys();
        $historial = [];
        $ultimo = [];
        if ($ids !== []) {
            SnkKilkanaCommentModel::query()
                ->whereIn('user_id', $ids)
                ->selectRaw('user_id, status, count(*) as total, max(created_at) as last_at')
                ->groupBy('user_id', 'status')
                ->get()
                ->each(function ($fila) use (&$historial, &$ultimo) {
                    $historial[$fila->user_id] ??= ['total' => 0, 'rejected' => 0];
                    $historial[$fila->user_id]['total'] += (int) $fila->total;
                    if ($fila->status === SnkKilkanaCommentStatusEnum::Rejected) {
                        $historial[$fila->user_id]['rejected'] += (int) $fila->total;
                    }
                    $ultimo[$fila->user_id] = max($ultimo[$fila->user_id] ?? '', (string) $fila->last_at);
                });
        }
        $lecturas = $ids === [] ? collect() : SnkKilkanaReaderReadModel::query()
            ->whereIn('user_id', $ids)
            ->inMonth()
            ->selectRaw('user_id, count(*) as total, max(read_at) as last_at')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');
        $boletin = $users->isEmpty() ? collect() : SnkKilkanaSubscriberModel::query()
            ->whereIn('email', $users->pluck('email'))
            ->get(['email', 'status', 'consent_source', 'consent_at'])
            ->keyBy('email');

        return $users->map(function (CoreUserModel $user) use ($historial, $ultimo, $lecturas, $boletin) {
            $id = $user->getKey();
            $suscripcion = $boletin->get($user->email);
            $lectura = $lecturas->get($id);
            // Como fechas y no como cadenas: hoy las tres llegan de un agregado en `Y-m-d H:i:s`,
            // pero el dia que una venga en ISO la `T` ordena por delante del espacio y ganaria
            // la mas vieja.
            $visto = collect([
                $user->getAttribute('last_token_at'),
                $ultimo[$id] ?? null,
                $lectura?->last_at,
            ])->filter()->map(fn ($v) => Carbon::parse($v))->max();

            return [
                'id' => (string) $id,
                'name' => $user->full_name ?: $user->name,
                'email' => $user->email,
                'since' => $user->created_at?->toIso8601String(),
                'comments' => $historial[$id]['total'] ?? 0,
                'rejected' => $historial[$id]['rejected'] ?? 0,
                'reads' => (int) ($lectura?->total ?? 0),
                'newsletter' => $suscripcion === null ? null : [
                    'status' => $suscripcion->status->value,
                    'consent_source' => $suscripcion->consent_source?->value,
                    'consent_at' => $suscripcion->consent_at?->toIso8601String(),
                ],
                'last_seen_at' => $visto?->toIso8601String(),
                'blocked' => $user->suspended_at !== null,
            ];
        })->values()->all();
    }
}
