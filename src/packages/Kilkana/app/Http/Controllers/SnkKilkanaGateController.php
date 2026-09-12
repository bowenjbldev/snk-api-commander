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
    public function __construct(private readonly SnkKilkanaActivityService $activityLog) {}

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

        $rows = SnkKilkanaGateResultModel::query()->orderBy('id')->get();
        $failing = $rows->filter(fn (SnkKilkanaGateResultModel $r) => $r->failing())->count();

        return response()->json([
            'data' => SnkKilkanaGateResultResource::collection($rows),
            'failing' => $failing,
            'last_run_at' => $rows->max('checked_at')?->toIso8601String(),
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

        $now = now();
        DB::transaction(function () use ($data, $now) {
            SnkKilkanaGateResultModel::query()->delete();
            foreach ($data['results'] as $row) {
                SnkKilkanaGateResultModel::create([
                    'url' => $row['url'],
                    'kind' => $row['kind'],
                    'passing' => $row['passing'] ?? null,
                    'checked_at' => isset($row['passing']) ? $now : null,
                ]);
            }
        });

        $rows = SnkKilkanaGateResultModel::query()->get();
        $failing = $rows->filter(fn (SnkKilkanaGateResultModel $r) => $r->failing())->count();
        $audited = $rows->whereNotNull('checked_at')->count();
        if ($failing > 0) {
            $this->activityLog->log('gate', 'deploy_blocked', '', '/output-gate', ['params' => ['failing' => $failing, 'total' => $audited]]);
        } else {
            $this->activityLog->log('gate', 'gate_passed', '', '/output-gate', ['params' => ['total' => $audited]]);
        }

        return $this->index();
    }
}
