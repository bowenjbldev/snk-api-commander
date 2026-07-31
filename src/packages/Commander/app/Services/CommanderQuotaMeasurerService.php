<?php

namespace Packages\Commander\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Packages\Commander\Models\CommanderActivityEventModel;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Core\Enums\CorePackageStatusEnum;
use Packages\Core\Services\CorePackageStateService;
use Packages\Core\Support\CoreActivePackageSupport;
use Packages\Core\Support\CorePackageSelectorSupport;

/**
 * Mide las metricas de cuota POR package y aplica la accion on_exceed. La unica via de
 * suspension es CorePackageStateService; el auto-heal solo revierte suspensiones causadas
 * por la propia metrica (reason quota_exceeded:<key>), nunca pisa una suspension manual
 * o del Commander. Cada metrica se mide acotada a su package (conmutando a su BD).
 */
class CommanderQuotaMeasurerService
{
    public function __construct(
        private readonly CorePackageStateService $stateService,
        private readonly CoreActivePackageSupport $active,
    ) {}

    public function measure(
        string $metricKey,
        ?CommanderPackageModel $package = null,
        bool $tenantConnected = false,
    ): ?int {
        $packageId = $package?->getKey();

        return match ($metricKey) {
            'disk_db_mb' => $this->measureDatabaseSizeMb($package, $tenantConnected),
            'disk_storage_mb' => $this->measureStorageSizeMb($package),
            'requests_day' => $this->measureRequestsToday($packageId),
            // users del package = todos los users de SU BD (la conexion 'tenant' ya apunta a ella).
            'users_total' => $tenantConnected
                ? (int) DB::connection('tenant')->table('users')->count()
                : null,
            default => null,
        };
    }

    /**
     * @return array<int, array{metric: CommanderQuotaModel, value: ?int, over: bool}>
     */
    public function measureAll(bool $persist = true, bool $enforce = true): array
    {
        $results = [];
        $originalTenantConfig = config('database.connections.tenant');
        $originalActivePackage = $this->active->current();

        // Agrupa por package: fija el contexto activo de cada grupo para que enforce()
        // (state->set/current) apunte a la fila packages correcta.
        $byPackage = CommanderQuotaModel::query()
            ->orderBy('package_id')
            ->orderBy('metric_key')
            ->get()
            ->groupBy('package_id');

        try {
            foreach ($byPackage as $packageId => $metrics) {
                $package = $packageId !== null ? CommanderPackageModel::query()->find($packageId) : null;
                $this->active->set($package);

                // Nunca permite que un registro incompleto reutilice la conexion del tenant anterior.
                config(['database.connections.tenant' => $originalTenantConfig]);
                DB::purge('tenant');
                $tenantConnected = $this->configureTenantConnection($package);
                $measuredMetrics = [];

                foreach ($metrics as $metric) {
                    $value = $this->measure($metric->metric_key, $package, $tenantConnected);

                    // Set the measured value in memory first so enforce() always sees fresh data; only the DB
                    // write is gated on $persist (enforcing on a stale current_value would mis-decide suspend/heal).
                    if ($value !== null) {
                        $metric->current_value = $value;
                        $measuredMetrics[] = $metric;

                        if ($persist) {
                            $metric->measured_at = now();
                            $metric->save();
                        }
                    }

                    $over = $value !== null && $metric->limit_value !== null && $value > $metric->limit_value;

                    $results[] = ['metric' => $metric, 'value' => $value, 'over' => $over];
                }

                if ($enforce) {
                    foreach ($measuredMetrics as $metric) {
                        $anotherMetricIsOver = $metrics->contains(
                            fn (CommanderQuotaModel $other): bool => ! $other->is($metric) && $other->isOverLimit(),
                        );
                        $this->enforce($metric, $anotherMetricIsOver);
                    }
                }
            }
        } finally {
            config(['database.connections.tenant' => $originalTenantConfig]);
            DB::purge('tenant');
            $this->active->set($originalActivePackage);
        }

        return $results;
    }

    public function enforce(CommanderQuotaModel $metric, bool $anotherMetricIsOver = false): void
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
                $message = "A usage limit was exceeded. Your service will be suspended if usage isn't reduced.";
                $this->stateService->set(
                    CorePackageStatusEnum::Grace,
                    $reason,
                    $message,
                    CarbonImmutable::now()->addHours((int) config('core.quotas.grace_hours', 72)),
                );
                $this->recordActivity($metric->package_id, 'grace', $reason, $message);

                return;
            }

            // Grace propio vencido => escalar a suspension definitiva
            if ($state->status === CorePackageStatusEnum::Grace
                && $state->reason === $reason
                && $state->effectiveStatus() === CorePackageStatusEnum::Suspended) {
                $message = 'A usage limit was exceeded and the grace period has ended.';
                $this->stateService->set(
                    CorePackageStatusEnum::Suspended,
                    $reason,
                    $message,
                    null,
                );
                $this->recordActivity($metric->package_id, 'suspend', $reason, $message);
            }

            return;
        }

        // De vuelta bajo el limite
        if ($metric->exceeded_at !== null) {
            $metric->exceeded_at = null;
            $metric->save();
        }

        // Auto-heal: solo si la suspension/grace la causo ESTA metrica
        if (! $anotherMetricIsOver
            && $state->status !== CorePackageStatusEnum::Active
            && $state->reason === $reason) {
            $this->stateService->set(CorePackageStatusEnum::Active, null, null, null);
            $this->recordActivity($metric->package_id, 'resume', null, null);
        }
    }

    /**
     * Registra el cambio de estado AUTOMATICO en el feed de actividad (commander_activity_events),
     * igual que las acciones manuales del operador. El origen (cuota vs operador) se distingue por
     * el `reason` (quota_exceeded:<key>), no por una columna de actor.
     */
    private function recordActivity(int $packageId, string $kind, ?string $reason, ?string $message): void
    {
        CommanderActivityEventModel::create([
            'kind' => $kind,
            'package_id' => $packageId,
            'invoice_id' => null,
            'reason' => $reason,
            'message' => $message,
        ]);
    }

    private function configureTenantConnection(?CommanderPackageModel $package): bool
    {
        if (empty($package?->database)
            || empty($package->database_username)
            || empty($package->database_password)) {
            return false;
        }

        config([
            'database.connections.tenant.database' => CorePackageSelectorSupport::validatedDatabaseIdentifier($package->database),
            'database.connections.tenant.username' => CorePackageSelectorSupport::validatedDatabaseIdentifier($package->database_username),
            'database.connections.tenant.password' => $package->database_password,
        ]);

        DB::purge('tenant');

        return true;
    }

    private function measureDatabaseSizeMb(?CommanderPackageModel $package, bool $tenantConnected): ?int
    {
        if ($package === null || ! $tenantConnected) {
            return null;
        }

        // El usuario runtime solo puede ver su BD; DATABASE() evita consultar metadatos de otro tenant.
        if (DB::connection('tenant')->getDriverName() === 'sqlite') {
            return null;
        }

        $row = DB::connection('tenant')->selectOne(
            'SELECT ROUND(SUM(data_length + index_length) / 1048576) AS mb
             FROM information_schema.tables WHERE table_schema = DATABASE()'
        );

        return (int) ($row->mb ?? 0);
    }

    private function measureStorageSizeMb(?CommanderPackageModel $package): ?int
    {
        if (empty($package?->database)) {
            return null;
        }

        $database = CorePackageSelectorSupport::validatedDatabaseIdentifier($package->database);
        $base = storage_path("app/public/media/{$database}");

        if (! is_dir($base)) {
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
        return (int) CommanderRequestLogModel::query()
            ->where('created_at', '>=', CarbonImmutable::now('UTC')->startOfDay())
            ->when($packageId !== null, fn ($q) => $q->where('package_id', $packageId))
            ->count();
    }
}
