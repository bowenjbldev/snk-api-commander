<?php

namespace Packages\Commander\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\DeploymentModel;
use Packages\Commander\Models\UsageSnapshotModel;
use Packages\Core\Models\CoreQuotaMetricModel;

/**
 * Refresca los campos denormalizados que el dashboard/lista del Commander leen
 * (requests_24h, quota_health, last_seen) y los snapshots de uso de plataforma,
 * recalculandolos IN-PROCESS desde las tablas core_* compartidas (mismo deploy/BD).
 * Reemplaza al antiguo commander:sync-deploys (que tiraba por HTTP firmado).
 */
class CommanderRefreshCommand extends Command
{
    protected $signature = 'commander:refresh';

    protected $description = 'Refresh deployment KPIs + usage snapshots from the shared core_* tables (in-process)';

    public function handle(): int
    {
        $deployments = DeploymentModel::query()->whereNotNull('package_id')->get();
        $since24 = CarbonImmutable::now()->subDay();

        foreach ($deployments as $dep) {
            $pid = $dep->package_id;

            $requests24h = (int) DB::table('core_request_logs')
                ->where('package_id', $pid)
                ->where('occurred_at', '>=', $since24)
                ->count();

            $lastSeen = DB::table('core_request_logs')->where('package_id', $pid)->max('occurred_at');

            $worst = 0.0;
            foreach (CoreQuotaMetricModel::query()->where('package_id', $pid)->get() as $m) {
                if ($m->limit_value !== null && $m->limit_value > 0) {
                    $worst = max($worst, $m->current_value / $m->limit_value);
                }

                // Refleja el valor medido (core_quota_metrics) en el espejo que lee el SPA.
                DB::table('commander_quota_metrics')
                    ->where('id', $dep->id . ':' . $m->metric_key)
                    ->update([
                        'current_value' => $m->current_value,
                        'exceeded_at' => $m->exceeded_at,
                        'updated_at' => now(),
                    ]);
            }

            $dep->forceFill([
                'requests_24h' => $requests24h,
                'quota_health' => round(min($worst, 99.9999), 4), // cabe en decimal(6,4)
                'last_seen' => $lastSeen ? CarbonImmutable::parse($lastSeen) : $dep->last_seen,
            ])->save();

            // Snapshots para el grafico de plataforma: suma los rollups por bucket de este package.
            foreach (['hour', 'day'] as $period) {
                $buckets = DB::table('core_usage_rollups')
                    ->where('package_id', $pid)
                    ->where('period', $period)
                    ->groupBy('period_start')
                    ->get([
                        'period_start',
                        DB::raw('SUM(request_count) as rc'),
                        DB::raw('SUM(error_count) as ec'),
                    ]);

                foreach ($buckets as $b) {
                    $start = CarbonImmutable::parse($b->period_start);
                    UsageSnapshotModel::query()->updateOrCreate(
                        ['id' => $dep->id . ':' . $period . ':' . $start->getTimestamp()],
                        [
                            'deployment_id' => $dep->id,
                            'period' => $period,
                            'period_start' => $start,
                            'request_count' => (int) $b->rc,
                            'error_count' => (int) $b->ec,
                        ],
                    );
                }
            }

            $this->line(sprintf('  %-16s %6d req/24h   health %.2f', $dep->id, $requests24h, round(min($worst, 99.9999), 4)));
        }

        $this->info('Refreshed ' . $deployments->count() . ' deployment(s).');

        return self::SUCCESS;
    }
}
