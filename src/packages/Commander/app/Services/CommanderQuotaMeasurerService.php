<?php

namespace Packages\Commander\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Packages\Commander\Contracts\CommanderMetricMeasurer;
use Packages\Commander\Models\CommanderActivityEventModel;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Commander\Models\CommanderMetricMeasurementModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Core\Enums\CoreServiceStatusEnum;
use Packages\Core\Services\CoreInstanceStateService;
use Packages\Core\Support\CoreActiveInstanceSupport;
use Packages\Core\Support\CorePackageSelectorSupport;

class CommanderQuotaMeasurerService
{
    public function __construct(
        private readonly CoreInstanceStateService $state,
        private readonly CoreActiveInstanceSupport $active,
    ) {}

    /** @return array<int, array{quota: CommanderQuotaModel, value: ?int, over: bool}> */
    public function measureAll(bool $persist = true, bool $enforce = true): array
    {
        $results = [];
        $originalTenantConfig = config('database.connections.tenant');
        $originalActive = $this->active->current();
        $groups = CommanderQuotaModel::query()
            ->with(['metric', 'instance'])
            ->orderBy('instance_id')
            ->get()
            ->groupBy('instance_id');

        try {
            foreach ($groups as $instanceId => $quotas) {
                $instance = CommanderInstanceModel::query()->find($instanceId);
                if ($instance === null || $instance->provisioning_status !== 'ready') {
                    continue;
                }

                $this->active->set($instance);
                config(['database.connections.tenant' => $originalTenantConfig]);
                DB::purge('tenant');
                try {
                    $tenantConnected = $this->configureTenant($instance);
                } catch (\Throwable $error) {
                    report($error);
                    foreach ($quotas as $quota) {
                        $results[] = ['quota' => $quota, 'value' => null, 'over' => false];
                    }

                    continue;
                }

                foreach ($quotas as $quota) {
                    if ($quota->metric->status !== 'active') {
                        continue;
                    }
                    $previousMeasuredAt = $quota->measured_at?->toImmutable();
                    try {
                        $measurer = app($quota->metric->measurer);
                        if (! $measurer instanceof CommanderMetricMeasurer) {
                            throw new \RuntimeException("Metric measurer [{$quota->metric->measurer}] is invalid.");
                        }
                        $value = $measurer->measure($instance, $quota->metric, $tenantConnected);
                    } catch (\Throwable $error) {
                        report($error);
                        $results[] = ['quota' => $quota, 'value' => null, 'over' => false];

                        continue;
                    }
                    $over = $value !== null && $quota->limit_value !== null && $value > $quota->limit_value;
                    $results[] = ['quota' => $quota, 'value' => $value, 'over' => $over];
                    if ($value === null) {
                        continue;
                    }

                    $quota->current_value = $value;
                    if ($persist) {
                        $quota->measured_at = now();
                        $quota->save();
                        CommanderMetricMeasurementModel::updateOrCreate(
                            ['quota_id' => $quota->id, 'bucket_date' => now()->toDateString()],
                            ['value' => $value, 'measured_at' => now()],
                        );
                    }

                    if ($enforce) {
                        $this->enforce($quota, $previousMeasuredAt);
                    }
                }
            }
        } finally {
            config(['database.connections.tenant' => $originalTenantConfig]);
            DB::purge('tenant');
            $this->active->set($originalActive);
        }

        return $results;
    }

    public function enforce(CommanderQuotaModel $quota, ?CarbonImmutable $previousMeasuredAt = null): void
    {
        if ($quota->limit_value === null) {
            return;
        }

        $threshold = (int) ceil($quota->limit_value * $quota->warning_percent / 100);
        if ($quota->current_value >= $threshold && $quota->threshold_reached_at === null) {
            $quota->threshold_reached_at = now();
            $quota->save();
            $this->recordActivity($quota->instance_id, 'quota_warning', "quota:{$quota->metric->key}", null);
        } elseif ($quota->current_value < $threshold && $quota->threshold_reached_at !== null) {
            $quota->threshold_reached_at = null;
            $quota->save();
        }

        if ($quota->action === 'recommend' && $this->enteredNewMonth($previousMeasuredAt)) {
            $this->evaluateUpgradeRecommendation($quota);
        }

        if (! $quota->isOverLimit()) {
            $quota->exceeded_at = null;
            $quota->save();
            $this->healSuspension($quota);

            return;
        }

        $quota->exceeded_at ??= now();
        $quota->save();

        if ($quota->action === 'warn') {
            Log::warning("Quota [{$quota->metric->key}] exceeded on instance [{$quota->instance_id}].");
        } elseif ($quota->action === 'suspend') {
            $this->suspend($quota);
        }
    }

    private function evaluateUpgradeRecommendation(CommanderQuotaModel $quota): void
    {
        $value = $quota->metric->key === 'reads_month'
            ? $this->threeMonthAverage($quota)
            : $this->lastCompletedMonthValue($quota);
        if ($value === null) {
            return;
        }

        $quota->consecutive_exceeded = $value > $quota->limit_value
            ? min(255, $quota->consecutive_exceeded + 1)
            : 0;
        if ($quota->consecutive_exceeded >= $quota->consecutive_periods
            && $quota->upgrade_recommended_at === null) {
            $quota->upgrade_recommended_at = now();
            $this->recordActivity($quota->instance_id, 'upgrade_recommended', "quota:{$quota->metric->key}", null);
        }
        $quota->save();
    }

    private function enteredNewMonth(?CarbonImmutable $previous): bool
    {
        return $previous !== null && ! $previous->isSameMonth(CarbonImmutable::now());
    }

    private function threeMonthAverage(CommanderQuotaModel $quota): ?int
    {
        $values = [];
        for ($monthsAgo = 1; $monthsAgo <= 3; $monthsAgo++) {
            $month = CarbonImmutable::now()->subMonthsNoOverflow($monthsAgo);
            $value = $quota->measurements()
                ->whereBetween('bucket_date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
                ->orderByDesc('bucket_date')
                ->value('value');
            if ($value === null) {
                return null;
            }
            $values[] = (int) $value;
        }

        return (int) round(array_sum($values) / 3);
    }

    private function lastCompletedMonthValue(CommanderQuotaModel $quota): ?int
    {
        $month = CarbonImmutable::now()->subMonthNoOverflow();
        $value = $quota->measurements()
            ->whereBetween('bucket_date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
            ->orderByDesc('bucket_date')
            ->value('value');

        return $value === null ? null : (int) $value;
    }

    private function suspend(CommanderQuotaModel $quota): void
    {
        $reason = "quota_exceeded:{$quota->metric->key}";
        $state = $this->state->current();
        if ($state->status === CoreServiceStatusEnum::Active) {
            $message = 'A usage limit was exceeded. The service will be suspended after the grace period.';
            $this->state->set(
                CoreServiceStatusEnum::Grace,
                $reason,
                $message,
                CarbonImmutable::now()->addHours((int) config('core.quotas.grace_hours', 72)),
            );
            $this->recordActivity($quota->instance_id, 'grace', $reason, $message);
        } elseif ($state->status === CoreServiceStatusEnum::Grace
            && $state->reason === $reason
            && $state->effectiveStatus() === CoreServiceStatusEnum::Suspended) {
            $this->state->set(CoreServiceStatusEnum::Suspended, $reason, 'Usage grace period ended.');
            $this->recordActivity($quota->instance_id, 'suspend', $reason, null);
        }
    }

    private function healSuspension(CommanderQuotaModel $quota): void
    {
        $reason = "quota_exceeded:{$quota->metric->key}";
        $anotherOver = CommanderQuotaModel::query()
            ->where('instance_id', $quota->instance_id)
            ->where('id', '!=', $quota->id)
            ->whereNotNull('limit_value')
            ->whereColumn('current_value', '>', 'limit_value')
            ->exists();
        $state = $this->state->current();
        if (! $anotherOver && $state->status !== CoreServiceStatusEnum::Active && $state->reason === $reason) {
            $this->state->set(CoreServiceStatusEnum::Active);
            $this->recordActivity($quota->instance_id, 'resume', null, null);
        }
    }

    private function configureTenant(CommanderInstanceModel $instance): bool
    {
        if (empty($instance->database) || empty($instance->database_username) || empty($instance->database_password)) {
            return false;
        }

        config([
            'database.connections.tenant.database' => CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database),
            'database.connections.tenant.username' => CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database_username),
            'database.connections.tenant.password' => $instance->database_password,
        ]);
        DB::purge('tenant');

        return true;
    }

    private function recordActivity(int $instanceId, string $kind, ?string $reason, ?string $message): void
    {
        CommanderActivityEventModel::create([
            'instance_id' => $instanceId,
            'invoice_id' => null,
            'kind' => $kind,
            'reason' => $reason,
            'message' => $message,
        ]);
    }
}
