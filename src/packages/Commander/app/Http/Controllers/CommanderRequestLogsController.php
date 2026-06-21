<?php

namespace Packages\Commander\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

/**
 * Logs de request de TODA la plataforma. Lee commander_request_logs y hace join con
 * commander_packages para etiquetar cada fila con package/subdomain. El filtro por package es opcional.
 */
#[Group('Request Logs')]
class CommanderRequestLogsController extends CoreBaseApiController
{
    protected string $modelClass = CommanderRequestLogModel::class;

    protected string $indexPermission = 'index packages';

    // Firma compatible con CoreBaseApiController::index(): la request se toma del helper request().
    public function index(): JsonResponse
    {
        if ($response = $this->requirePermission($this->indexPermission)) {
            return $response;
        }

        $data = request()->validate([
            'package' => 'nullable|string|max:100',
            'limit' => 'nullable|integer|min:1|max:500',
            'since' => 'nullable|date',
            'status_class' => 'nullable|integer|in:2,3,4,5',
            'method' => 'nullable|string|max:10',
        ]);

        $rows = CommanderRequestLogModel::query()
            ->from('commander_request_logs as l')
            ->leftJoin('commander_packages as p', 'p.id', '=', 'l.package_id')
            ->when(isset($data['package']), fn ($q) => $q->where('p.name', $data['package']))
            ->when(isset($data['since']), fn ($q) => $q->where('l.occurred_at', '>=', $data['since']))
            ->when(isset($data['method']), fn ($q) => $q->where('l.method', strtoupper($data['method'])))
            ->when(isset($data['status_class']), function ($q) use ($data) {
                $base = ((int) $data['status_class']) * 100;

                return $q->where('l.status', '>=', $base)->where('l.status', '<', $base + 100);
            })
            ->orderByDesc('l.occurred_at')
            ->limit($data['limit'] ?? 100)
            ->get(['l.*', 'p.name as package', 'p.subdomain as subdomain'])
            ->all();

        return response()->json(['data' => $rows]);
    }
}
