<?php

namespace Packages\Core\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Packages\Core\Enums\CorePackageStatusEnum;
use Packages\Core\Models\CorePackageModel;
use Packages\Core\Models\CoreQuotaMetricModel;
use Packages\Core\Support\CoreActivePackageSupport;

/**
 * Mide las metricas de cuota POR package y aplica la accion on_exceed. La unica via de
 * suspension es CorePackageStateService; el auto-heal solo revierte suspensiones causadas
 * por la propia metrica (reason quota_exceeded:<key>), nunca pisa una suspension manual
 * o del Commander. En una BD compartida cada metrica se mide acotada a su package.
 */
class CoreQuotaMeasurerService
{
    public function __construct(
        private readonly CorePackageStateService $stateService,
        private readonly CoreActivePackageSupport $active,
    ) {
    }

    public function measure(string $metricKey, ?CorePackageModel $package = null): ?int
    {
        $packageId = $package?->getKey();

        return match ($metricKey) {
            'disk_db_mb' => $this->measureDatabaseSizeMb($package),
            'disk_storage_mb' => $this->measureStorageSizeMb(),
            'requests_day' => $this->measureRequestsToday($packageId),
            // users del package = todos los users de SU BD (la conexion 'tenant' ya apunta a ella).
            'users_total' => ! empty($package?->database)
                ? (int) DB::connection('tenant')->table('users')->count()
                : 0,
            default => null,
        };
    }

    /**
     * @return array<int, array{metric: CoreQuotaMetricModel, value: ?int, over: bool}>
     */
    public function measureAll(bool $persist = true, bool $enforce = true): array
    {
        $results = [];

        // Agrupa por package: fija el contexto activo de cada grupo para que enforce()
        // (state->set/current) apunte a la fila core_packages correcta.
        $byPackage = CoreQuotaMetricModel::query()
            ->orderBy('package_id')
            ->orderBy('metric_key')
            ->get()
            ->groupBy('package_id');

        foreach ($byPackage as $packageId => $metrics) {
            $package = $packageId !== null ? CorePackageModel::query()->find($packageId) : null;
            $this->active->set($package);

            // Apunta la conexion 'tenant' a la BD del package para medir lo LOCAL (users, tamaño BD).
            // requests_day y las escrituras de core_quota_metrics siguen yendo a 'central'.
            if (! empty($package?->database)) {
                config(['database.connections.tenant.database' => $package->database]);
                DB::purge('tenant');
            }

            foreach ($metrics as $metric) {
                $value = $this->measure($metric->metric_key, $package);

                // Set the measured value in memory first so enforce() always sees fresh data; only the DB
                // write is gated on $persist (enforcing on a stale current_value would mis-decide suspend/heal).
                if ($value !== null) {
                    $metric->current_value = $value;

                    if ($persist) {
                        $metric->measured_at = now();
                        $metric->save();
                    }
                }

                $over = $value !== null && $metric->limit_value !== null && $value > $metric->limit_value;

                if ($enforce && $value !== null) {
                    $this->enforce($metric);
                }

                $results[] = ['metric' => $metric, 'value' => $value, 'over' => $over];
            }
        }

        $this->active->forget();

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

    private function measureDatabaseSizeMb(?CorePackageModel $package): ?int
    {
        if ($package === null || empty($package->database)) {
            return null;
        }

        // Aislamiento por BD: el tamaño = el de TODA la BD del package (information_schema es global,
        // se consulta por la central). En sqlite (tests) no hay information_schema.
        if (DB::connection('central')->getDriverName() === 'sqlite') {
            return null;
        }

        $row = DB::connection('central')->selectOne(
            'SELECT ROUND(SUM(data_length + index_length) / 1048576) AS mb
             FROM information_schema.tables WHERE table_schema = ?',
            [$package->database]
        );

        return (int) ($row->mb ?? 0);
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

    private function measureRequestsToday(?int $packageId): int
    {
        // Nota: con sample_rate < 1 esto infra-cuenta; escalar 1/sample_rate si se usa sampling.
        return (int) DB::connection('central')->table('core_request_logs')
            ->where('occurred_at', '>=', CarbonImmutable::now('UTC')->startOfDay())
            ->when($packageId !== null, fn ($q) => $q->where('package_id', $packageId))
            ->count();
    }
}
