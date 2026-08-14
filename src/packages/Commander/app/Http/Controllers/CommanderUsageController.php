<?php

namespace Packages\Commander\Http\Controllers;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderUsageRollupModel;
use Packages\Core\Http\Controllers\CoreBaseApiController;

/**
 * Platform-wide usage read for the Dashboard/Usage charts. Aggregates `commander_usage_rollups`
 * on the fly (SUM by instance + bucket) so the charts render from one query — no derived cache table.
 */
#[Group('Usage')]
class CommanderUsageController extends CoreBaseApiController
{
    public function platform(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission('index instances')) {
            return $response;
        }

        $data = $request->validate([
            'period' => 'nullable|in:hour,day',
            'hours' => 'nullable|integer|min:1|max:8784', // up to ~366 days (year range, daily period)
        ]);

        $period = $data['period'] ?? 'hour';
        $since = CarbonImmutable::now()->subHours((int) ($data['hours'] ?? 48));

        // Suma los rollups por (instance, bucket); aplicación/cuenta se derivan de la instancia.
        $rows = CommanderUsageRollupModel::query()
            ->where('period', $period)
            ->where('period_start', '>=', $since)
            ->groupBy('instance_id', 'period_start')
            ->orderBy('period_start')
            ->get([
                'instance_id',
                'period_start',
                DB::raw('SUM(request_count) as request_count'),
                DB::raw('SUM(error_count) as error_count'),
                DB::raw("SUM(CASE WHEN status_class = '4xx' THEN request_count ELSE 0 END) as count_4xx"),
                DB::raw("SUM(CASE WHEN status_class = '5xx' THEN request_count ELSE 0 END) as count_5xx"),
            ]);

        return response()->json(['data' => $rows]);
    }
}
