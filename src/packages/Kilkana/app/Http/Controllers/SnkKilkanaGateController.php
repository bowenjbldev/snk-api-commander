<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Kilkana\Http\Resources\SnkKilkanaGateResultResource;
use Packages\Kilkana\Models\SnkKilkanaGateResultModel;
use Packages\Kilkana\Services\SnkKilkanaActivityService;

/**
 * El output gate: el veredicto de la última pasada sobre cada URL pública.
 *
 * No audita: audita la salida pública, que es quien sirve el HTML. Aquí se guarda lo que dijo
 * y se sirve al panel, y una pasada nueva sustituye la anterior entera.
 */
#[Group('Output gate')]
class SnkKilkanaGateController extends CoreBaseApiController
{
    public function __construct(private readonly SnkKilkanaActivityService $registro) {}

    protected string $modelClass = SnkKilkanaGateResultModel::class;

    protected string $indexPermission = 'index posts';

    /** Correr el gate es lo que precede a un despliegue: lo hace quien publica. */
    protected string $storePermission = 'publish posts';

    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        // La cifra sola no necesita las filas: es lo que pide el raíl del panel en cada carga.
        if (request()->boolean('counts')) {
            return response()->json(['failing' => SnkKilkanaGateResultModel::failingCount()]);
        }

        $filas = SnkKilkanaGateResultModel::query()->orderBy('id')->get();
        $failing = $filas->filter(fn (SnkKilkanaGateResultModel $r) => $r->failing())->count();

        return response()->json([
            'data' => SnkKilkanaGateResultResource::collection($filas),
            'failing' => $failing,
            'last_run_at' => $filas->max('checked_at')?->toIso8601String(),
        ]);
    }

    /**
     * Una pasada entera: las rutas descubiertas, con su veredicto o sin construir. Sustituye
     * la anterior, y deja rastro en el registro si algo bloquea el despliegue.
     */
    public function store(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission($this->storePermission)) {
            return $response;
        }

        $data = $request->validate([
            'results' => 'required|array|max:500',
            'results.*.url' => 'required|string|max:500',
            'results.*.kind' => 'required|string|max:32',
            'results.*.passing' => 'nullable|array|size:'.SnkKilkanaGateResultModel::CHECKS,
            'results.*.passing.*' => 'boolean',
        ]);

        $ahora = now();
        DB::transaction(function () use ($data, $ahora) {
            SnkKilkanaGateResultModel::query()->delete();
            foreach ($data['results'] as $fila) {
                SnkKilkanaGateResultModel::create([
                    'url' => $fila['url'],
                    'kind' => $fila['kind'],
                    'passing' => $fila['passing'] ?? null,
                    'checked_at' => isset($fila['passing']) ? $ahora : null,
                ]);
            }
        });

        $filas = SnkKilkanaGateResultModel::query()->get();
        $failing = $filas->filter(fn (SnkKilkanaGateResultModel $r) => $r->failing())->count();
        $auditadas = $filas->whereNotNull('checked_at')->count();
        if ($failing > 0) {
            $this->registro->log('gate', 'deploy_blocked', '', '/output-gate', ['params' => ['failing' => $failing, 'total' => $auditadas]]);
        } else {
            $this->registro->log('gate', 'gate_passed', '', '/output-gate', ['params' => ['total' => $auditadas]]);
        }

        return $this->index();
    }
}
