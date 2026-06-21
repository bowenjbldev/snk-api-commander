<?php

namespace Packages\Commander\Http\Controllers;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Commander\Models\CommanderUsageRollupModel;

/**
 * Platform-wide usage read for the Dashboard/Usage charts. Aggregates `commander_usage_rollups`
 * on the fly (SUM by package + bucket) so the charts render from one query — no derived cache table.
 */
#[Group('Usage')]
class CommanderUsageController extends CoreBaseApiController
{
    public function platform(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission('index packages')) {
            return $response;
        }

        $data = $request->validate([
            'period' => 'nullable|in:hour,day',
            'hours' => 'nullable|integer|min:1|max:720',
        ]);

        $period = $data['period'] ?? 'hour';
        $since = CarbonImmutable::now()->subHours((int) ($data['hours'] ?? 48));

        // Suma los rollups por (package, bucket): el chart de plataforma solo necesita totales por
        // package y hora/dia. Datos siempre frescos (sin tabla de snapshots intermedia).
        $rows = CommanderUsageRollupModel::query()
            ->where('period', $period)
            ->where('period_start', '>=', $since)
            ->groupBy('package_id', 'period_start')
            ->orderBy('period_start')
            ->get([
                'package_id',
                'period_start',
                DB::raw('SUM(request_count) as request_count'),
                DB::raw('SUM(error_count) as error_count'),
            ]);

        return response()->json(['data' => $rows]);
    }
}
