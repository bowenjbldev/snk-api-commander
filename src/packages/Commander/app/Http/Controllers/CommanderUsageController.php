<?php

namespace Packages\Commander\Http\Controllers;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use Packages\Commander\Models\UsageSnapshotModel;

/**
 * Platform-wide usage read for the Dashboard/Usage charts. Aggregates the local
 * commander_usage_snapshots cache (seeded + refreshed by deployment `sync`) so the charts render without
 * N live /control/usage calls.
 */
#[Group('Usage')]
class CommanderUsageController extends CoreBaseApiController
{
    public function platform(Request $request): JsonResponse
    {
        if ($response = $this->requirePermission('index deployments')) {
            return $response;
        }

        $data = $request->validate([
            'period' => 'nullable|in:hour,day',
            'hours' => 'nullable|integer|min:1|max:720',
        ]);

        $period = $data['period'] ?? 'hour';
        $since = CarbonImmutable::now()->subHours((int) ($data['hours'] ?? 48));

        $rows = UsageSnapshotModel::query()
            ->where('period', $period)
            ->where('period_start', '>=', $since)
            ->orderBy('period_start')
            ->get(['deployment_id', 'period_start', 'request_count', 'error_count']);

        return response()->json(['data' => $rows]);
    }
}
