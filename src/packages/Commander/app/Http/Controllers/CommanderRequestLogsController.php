<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

/**
 * Logs de request de TODA la plataforma. Lee commander_request_logs y hace join con
 * commander_instances para etiquetar cada fila con aplicación, cliente y cuenta.
 */
#[Group('Request Logs')]
class CommanderRequestLogsController extends CoreBaseApiController
{
    protected string $modelClass = CommanderRequestLogModel::class;

    protected string $indexPermission = 'index instances';

    // Firma compatible con CoreBaseApiController::index(): la request se toma del helper request().
    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        $data = request()->validate([
            'application' => 'nullable|string|max:100',
            'instance_id' => 'nullable|integer|exists:commander_instances,id',
            'account_id' => 'nullable|integer|exists:commander_accounts,id',
            'limit' => 'nullable|integer|min:1|max:500',
            'since' => 'nullable|date',
            'status_class' => 'nullable|integer|in:2,3,4,5',
            'method' => 'nullable|string|max:10',
        ]);

        $rows = CommanderRequestLogModel::query()
            ->from('commander_request_logs as l')
            ->leftJoin('commander_instances as i', 'i.id', '=', 'l.instance_id')
            ->leftJoin('commander_applications as a', 'a.id', '=', 'i.application_id')
            ->leftJoin('commander_clients as c', 'c.id', '=', 'i.client_id')
            ->when(isset($data['application']), fn ($q) => $q->where('a.slug', $data['application']))
            ->when(isset($data['instance_id']), fn ($q) => $q->where('l.instance_id', $data['instance_id']))
            ->when(isset($data['account_id']), fn ($q) => $q->where('c.account_id', $data['account_id']))
            ->when(isset($data['since']), fn ($q) => $q->where('l.created_at', '>=', $data['since']))
            ->when(isset($data['method']), fn ($q) => $q->where('l.method', strtoupper($data['method'])))
            ->when(isset($data['status_class']), function ($q) use ($data) {
                $base = ((int) $data['status_class']) * 100;

                return $q->where('l.status', '>=', $base)->where('l.status', '<', $base + 100);
            })
            ->orderByDesc('l.created_at')
            ->limit($data['limit'] ?? 100)
            ->get(['l.*', 'a.slug as application', 'a.package_name', 'c.slug as client'])
            ->all();

        return response()->json(['data' => $rows]);
    }
}
