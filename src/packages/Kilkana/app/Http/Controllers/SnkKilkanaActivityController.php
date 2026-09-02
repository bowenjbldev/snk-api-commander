<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Kilkana\Models\SnkKilkanaActivityModel;

/**
 * El registro de la redacción: una línea de tiempo, no una tabla.
 *
 * Se sirve entero —los últimos noventa días— y sin paginar, porque partirlo rompe justo lo
 * que se viene a mirar, que es qué pasó antes de qué. Para acotar están la pestaña y la
 * persona; `count` recorta por arriba para el buzón del armazón, y con él solo viajan filas.
 * Todo lo que aquí se cuenta está acotado a esos noventa días por `recent()`: la tabla solo
 * crece, y una cuenta sin cota envejecería con ella.
 */
#[Group('Activity')]
class SnkKilkanaActivityController extends CoreBaseApiController
{
    private const COUNT_MAX = 500;

    protected string $modelClass = SnkKilkanaActivityModel::class;

    public function index(): JsonResponse
    {
        $request = request();
        $tab = (string) $request->query('tab', 'all');
        $who = trim((string) $request->query('who', ''));
        $count = (int) $request->query('count', (string) self::COUNT_MAX);
        $count = $count < 1 ? self::COUNT_MAX : min($count, self::COUNT_MAX);

        $visible = $this->visible();
        $filas = (clone $visible)
            ->when(isset(SnkKilkanaActivityModel::TABS[$tab]), fn (Builder $q) => $q->whereIn('kind', SnkKilkanaActivityModel::TABS[$tab]))
            ->when($who !== '', fn (Builder $q) => $q->where('actor_name', $who))
            ->limit($count)
            ->get();

        $data = $filas->map(fn (SnkKilkanaActivityModel $a) => [
            'id' => (string) $a->id,
            'who' => $a->actor_name,
            'role' => $a->actor_role,
            'system' => $a->user_id === null && $a->actor_name === SnkKilkanaActivityModel::SYSTEM,
            'kind' => $a->kind,
            'verb' => $a->verb,
            'what' => $a->what,
            'before' => $a->before,
            'after' => $a->after,
            'href' => $a->href,
            'at' => $a->created_at->toIso8601String(),
        ])->values();

        // Un adelanto —la campana— es solo filas: la gente y las cifras son cinco consultas
        // más que el armazón pagaría en cada carga para no pintarlas.
        if ($count < self::COUNT_MAX) {
            return response()->json(['data' => $data]);
        }

        $semana = now()->subDays(7);

        return response()->json([
            'data' => $data,
            // Quién aparece, para el desplegable: todo el registro visible, no lo filtrado.
            'people' => (clone $visible)->reorder()->distinct()->orderBy('actor_name')->pluck('actor_name')->values(),
            'stats' => [
                'today' => (clone $visible)->where('created_at', '>=', now()->startOfDay())->count(),
                'active_people' => (clone $visible)->where('created_at', '>=', $semana)->whereNotNull('user_id')->distinct()->count('user_id'),
                'went_out' => (clone $visible)->whereIn('kind', ['publish', 'schedule'])->whereIn('verb', ['published', 'scheduled'])->count(),
                'gate' => (clone $visible)->where('kind', 'gate')->count(),
            ],
        ]);
    }

    /**
     * Lo que puede ver quien pregunta. Los movimientos de cuentas —invitar, suspender— son
     * de quien administra cuentas: el resto de la redacción no ve quién entró ni quién salió.
     */
    private function visible(): Builder
    {
        $query = $this->newQuery()->recent();
        if (! auth()->user()?->can('index users')) {
            $query->where('kind', '<>', 'seat');
        }

        return $query;
    }
}
