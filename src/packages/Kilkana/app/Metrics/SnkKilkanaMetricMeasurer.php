<?php

namespace Packages\Kilkana\Metrics;

use Illuminate\Support\Facades\DB;
use Packages\Commander\Contracts\CommanderMetricMeasurer;
use Packages\Commander\Models\CommanderApplicationMetricModel;
use Packages\Commander\Models\CommanderInstanceModel;

class SnkKilkanaMetricMeasurer implements CommanderMetricMeasurer
{
    public function measure(
        CommanderInstanceModel $instance,
        CommanderApplicationMetricModel $metric,
        bool $tenantConnected,
    ): ?int {
        if (! $tenantConnected) {
            return null;
        }

        return match ($metric->key) {
            'editorial_seats' => (int) DB::connection('tenant')
                ->table('model_has_roles as assignments')
                ->join('roles', 'roles.id', '=', 'assignments.role_id')
                ->whereNotIn('roles.name', ['reader', 'service'])
                ->distinct('assignments.model_id')
                ->count('assignments.model_id'),
            'reads_month' => (int) DB::connection('tenant')
                ->table('kilkana_post_views')
                ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->sum('engaged'),
            default => null,
        };
    }
}
