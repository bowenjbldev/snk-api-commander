<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

/**
 * Logs de request de TODA la plataforma (no por-deployment). Lee commander_request_logs y hace
 * join con commander_deployments (por package_id) para etiquetar cada fila con su deployment_id.
 * El dropdown de deployment del SPA pasa a ser un filtro OPCIONAL.
 */
#[Group('Request Logs')]
class CommanderRequestLogsController extends CoreBaseApiController
{
    protected string $modelClass = CommanderRequestLogModel::class;

    protected string $indexPermission = 'index deployments';

    // Firma compatible con CoreBaseApiController::index(): la request se toma del helper request().
    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        $data = request()->validate([
            'deployment_id' => 'nullable|string|max:64', // filtra por el slug legible del deployment
            'limit' => 'nullable|integer|min:1|max:500',
            'since' => 'nullable|date',
            'status_class' => 'nullable|integer|in:2,3,4,5',
            'method' => 'nullable|string|max:10',
        ]);

        $rows = CommanderRequestLogModel::query()
            ->from('commander_request_logs as l')
            ->leftJoin('commander_deployments as d', 'd.package_id', '=', 'l.package_id')
            ->when(isset($data['deployment_id']), fn ($q) => $q->where('d.slug', $data['deployment_id']))
            ->when(isset($data['since']), fn ($q) => $q->where('l.occurred_at', '>=', $data['since']))
            ->when(isset($data['method']), fn ($q) => $q->where('l.method', strtoupper($data['method'])))
            ->when(isset($data['status_class']), function ($q) use ($data) {
                $base = ((int) $data['status_class']) * 100;

                return $q->where('l.status', '>=', $base)->where('l.status', '<', $base + 100);
            })
            ->orderByDesc('l.occurred_at')
            ->limit($data['limit'] ?? 100)
            ->get(['l.*', 'd.slug as deployment_id']) // tag legible (slug), no el bigint
            ->all();

        return response()->json(['data' => $rows]);
    }
}
