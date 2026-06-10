<?php

namespace Packages\Core\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Packages\Core\Enums\CorePackageStatusEnum;
use Packages\Core\Models\CoreQuotaMetricModel;

/**
 * Mide las metricas de cuota y aplica la accion on_exceed. La unica via de
 * suspension es CorePackageStateService; el auto-heal solo revierte
 * suspensiones causadas por la propia metrica (reason quota_exceeded:<key>),
 * nunca pisa una suspension manual o del CMS.
 */
class CoreQuotaMeasurerService
{
    public function __construct(private readonly CorePackageStateService $stateService)
    {
    }

    public function measure(string $metricKey): ?int
    {
        return match ($metricKey) {
            'disk_db_mb' => $this->measureDatabaseSizeMb(),
            'disk_storage_mb' => $this->measureStorageSizeMb(),
            'requests_day' => $this->measureRequestsToday(),
            'users_total' => (int) DB::table('users')->count(),
            default => null,
        };
    }

    /**
     * @return array<int, array{metric: CoreQuotaMetricModel, value: ?int, over: bool}>
     */
    public function measureAll(bool $persist = true, bool $enforce = true): array
    {
        $results = [];

        foreach (CoreQuotaMetricModel::query()->orderBy('metric_key')->get() as $metric) {
            $value = $this->measure($metric->metric_key);

            if ($persist && $value !== null) {
                $metric->current_value = $value;
                $metric->measured_at = now();
                $metric->save();
            }

            $over = $value !== null && $metric->limit_value !== null && $value > $metric->limit_value;

            if ($enforce && $value !== null) {
                $this->enforce($metric);
            }

            $results[] = ['metric' => $metric, 'value' => $value, 'over' => $over];
        }

        return $results;
    }

    public function enforce(CoreQuotaMetricModel $metric): void
    {
        $reason = "quota_exceeded:{$metric->metric_key}";
        $state = $this->stateService->current();

        if ($metric->isOverLimit()) {
            if ($metric->exceeded_at === null) {
                $metric->exceeded_at = now();
                $metric->save();
            }

            if ($metric->on_exceed === 'warn') {
                Log::warning("Quota [{$metric->metric_key}] excedida: {$metric->current_value} > {$metric->limit_value}");
                return;
            }

            if ($metric->on_exceed !== 'suspend') {
                return;
            }

            if ($state->status === CorePackageStatusEnum::Active) {
                $this->stateService->set(
                    CorePackageStatusEnum::Grace,
                    $reason,
                    "Cuota [{$metric->metric_key}] excedida. El servicio se suspendera si no se corrige.",
                    CarbonImmutable::now()->addHours((int) config('core.quotas.grace_hours', 72)),
                    'quota',
                );
                return;
            }

            // Grace propio vencido => escalar a suspension definitiva
            if ($state->status === CorePackageStatusEnum::Grace
                && $state->reason === $reason
                && $state->effectiveStatus() === CorePackageStatusEnum::Suspended) {
                $this->stateService->set(
                    CorePackageStatusEnum::Suspended,
                    $reason,
                    "Cuota [{$metric->metric_key}] excedida y periodo de gracia agotado.",
                    null,
                    'quota',
                );
            }

            return;
        }

        // De vuelta bajo el limite
        if ($metric->exceeded_at !== null) {
            $metric->exceeded_at = null;
            $metric->save();
        }

        // Auto-heal: solo si la suspension/grace la causo ESTA metrica
        if ($state->status !== CorePackageStatusEnum::Active && $state->reason === $reason) {
            $this->stateService->set(CorePackageStatusEnum::Active, null, null, null, 'quota');
        }
    }

    private function measureDatabaseSizeMb(): ?int
    {
        $connection = DB::connection();

        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => (int) ($connection->selectOne(
                'SELECT ROUND(SUM(data_length + index_length) / 1048576) AS mb
                 FROM information_schema.tables WHERE table_schema = DATABASE()'
            )->mb ?? 0),
            'sqlite' => $this->measureSqliteSizeMb($connection->getConfig('database')),
            default => null,
        };
    }

    private function measureSqliteSizeMb(?string $path): ?int
    {
        if ($path === null || $path === ':memory:' || !is_file($path)) {
            return null;
        }

        $bytes = (int) filesize($path);

        if (is_file($path . '-wal')) {
            $bytes += (int) filesize($path . '-wal');
        }

        return (int) round($bytes / 1048576);
    }

    private function measureStorageSizeMb(): int
    {
        $base = storage_path('app');

        if (!is_dir($base)) {
            return 0;
        }

        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $bytes += $file->getSize();
            }
        }

        return (int) round($bytes / 1048576);
    }

    private function measureRequestsToday(): int
    {
        // Nota: con sample_rate < 1 esto infra-cuenta; escalar 1/sample_rate si se usa sampling.
        return (int) DB::table('core_request_logs')
            ->where('occurred_at', '>=', CarbonImmutable::now('UTC')->startOfDay())
            ->count();
    }
}
