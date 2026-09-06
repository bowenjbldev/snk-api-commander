<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Kilkana\Models\SnkKilkanaActivityModel;
use Packages\Kilkana\Services\SnkKilkanaActivityService;

/**
 * El registro de la redacción: una línea de tiempo por páginas.
 *
 * Se sirve del más reciente al más antiguo dentro de los últimos noventa días, y por páginas
 * porque la tabla solo crece: un diario con diez redactores escribe aquí cientos de líneas al
 * día. Para acotar están la pestaña y la persona; `count` recorta por arriba para el buzón del
 * armazón, y con él solo viajan filas; `all` se lleva lo filtrado entero hasta un techo, que es
 * lo que pide un export. Todo lo que aquí se cuenta está acotado a esos noventa días por
 * `recent()`: una cuenta sin cota envejecería con la tabla.
 */
#[Group('Activity')]
class SnkKilkanaActivityController extends CoreBaseApiController
{
    /** El registro trae el doble que un listado editorial: son líneas de una línea. */
    protected int $perPageDefault = 50;

    private const EXPORT_MAX = 5000;

    public function __construct(private readonly SnkKilkanaActivityService $registro) {}

    /**
     * `people` es el desplegable de quién aparece —todo el registro visible, no lo filtrado— y
     * `stats` la banda. Ninguna de las dos cambia al pasar de página.
     */
    protected array $allowedSummary = ['people', 'stats'];

    protected string $modelClass = SnkKilkanaActivityModel::class;

    /** Abrir la campana: desde ahora, lo que hay es leído. */
    public function seen(): JsonResponse
    {
        $user = auth()->user();
        $user->forceFill(['activity_seen_at' => now()])->save();

        return response()->json(['seen_at' => now()->toIso8601String()]);
    }

    public function index(): JsonResponse
    {
        $request = request();
        $tab = (string) $request->query('tab', 'all');
        $who = trim((string) $request->query('who', ''));
        // `bell` y no `count`: se llamaba así y significaba «cuántas filas del adelanto»,
        // mientras `counts` en otros recursos significa «solo las cifras». Dos ideas separadas
        // por una letra, y nadie las lee con calma a las tres de la mañana.
        $adelanto = (int) $request->query('bell', '0');

        $visible = $this->registro->visible();
        $filtrado = (clone $visible)
            ->when(isset(SnkKilkanaActivityModel::TABS[$tab]), fn (Builder $q) => $q->whereIn('kind', SnkKilkanaActivityModel::TABS[$tab]))
            ->when($who !== '', fn (Builder $q) => $q->where('actor_name', $who));

        $fila = $this->registro->row(...);

        // Un adelanto —la campana— es solo filas: la gente y las cifras son cinco consultas
        // más que el armazón pagaría en cada carga para no pintarlas.
        if ($adelanto > 0) {
            return response()->json($this->registro->bell($adelanto, $filtrado));
        }
        // Un export se lleva lo filtrado entero, hasta el techo.
        if ($request->boolean('all')) {
            return response()->json(['data' => $filtrado->limit(self::EXPORT_MAX)->get()->map($fila)->values()]);
        }

        $page = $filtrado->paginate($this->perPage());
        $semana = now()->subDays(7);

        return response()->json([
            'data' => $page->getCollection()->map($fila)->values(),
            'meta' => $this->paginationMeta($page),
            // Quién aparece, para el desplegable: todo el registro visible, no lo filtrado.
            ...$this->wants('people') ? [
                'people' => (clone $visible)->reorder()->distinct()->orderBy('actor_name')->pluck('actor_name')->values(),
            ] : [],
            ...$this->wants('stats') ? [
                'stats' => [
                    'today' => (clone $visible)->where('created_at', '>=', now()->startOfDay())->count(),
                    'active_people' => (clone $visible)->where('created_at', '>=', $semana)->whereNotNull('user_id')->distinct()->count('user_id'),
                    'went_out' => (clone $visible)->whereIn('kind', ['publish', 'schedule'])->whereIn('verb', ['published', 'scheduled'])->count(),
                    'gate' => (clone $visible)->where('kind', 'gate')->count(),
                ],
            ] : [],
        ]);
    }
}
