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
use Packages\Commander\Models\CommanderSubscriptionItemModel;
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
        $measured = [];
        $originalTenantConfig = config('database.connections.tenant');
        $originalActive = $this->active->current();
        $groups = CommanderQuotaModel::query()
            ->whereNotNull('instance_id')
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
                        if ($quota->metric->status !== 'active') {
                            continue;
                        }
                        $measured[(int) $instanceId][$quota->metric_id] = null;
                        $this->recordMeasurement($results, $quota, null, $persist, $enforce);
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
                        $measured[(int) $instanceId][$quota->metric_id] = null;
                        $this->recordMeasurement($results, $quota, null, $persist, $enforce);

                        continue;
                    }
                    $measured[(int) $instanceId][$quota->metric_id] = $value;
                    $this->recordMeasurement($results, $quota, $value, $persist, $enforce, $previousMeasuredAt);
                }
            }

            foreach (CommanderQuotaModel::query()
                ->whereNotNull('subscription_id')
                ->with('metric')
                ->orderBy('subscription_id')
                ->get() as $quota) {
                if ($quota->metric->status !== 'active') {
                    continue;
                }
                $this->recordMeasurement(
                    $results,
                    $quota,
                    $this->aggregateSubscriptionQuota($quota, $measured),
                    $persist,
                    $enforce,
                );
            }
        } finally {
            config(['database.connections.tenant' => $originalTenantConfig]);
            DB::purge('tenant');
            $this->active->set($originalActive);
        }

        return $results;
    }

    /**
     * @param  array<int, array{quota: CommanderQuotaModel, value: ?int, over: bool}>  $results
     */
    private function recordMeasurement(
        array &$results,
        CommanderQuotaModel $quota,
        ?int $value,
        bool $persist,
        bool $enforce,
        ?CarbonImmutable $previousMeasuredAt = null,
    ): void {
        $over = $value !== null && $quota->limit_value !== null && $value > $quota->limit_value;
        $results[] = ['quota' => $quota, 'value' => $value, 'over' => $over];
        if ($value === null) {
            return;
        }

        $previousMeasuredAt ??= $quota->measured_at?->toImmutable();
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

    /** @param array<int, array<int, ?int>> $measured */
    private function aggregateSubscriptionQuota(CommanderQuotaModel $quota, array $measured): ?int
    {
        $total = 0;
        foreach ($this->targetInstanceIds($quota) as $instanceId) {
            if (! isset($measured[$instanceId])
                || ! array_key_exists($quota->metric_id, $measured[$instanceId])
                || $measured[$instanceId][$quota->metric_id] === null) {
                return null;
            }
            $total += $measured[$instanceId][$quota->metric_id];
        }

        return $total;
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
            $this->recordActivity($quota, 'quota_warning', "quota:{$quota->metric->key}", null);
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
            $scope = $quota->subscription_id !== null
                ? "subscription [{$quota->subscription_id}]"
                : "instance [{$quota->instance_id}]";
            Log::warning("Quota [{$quota->metric->key}] exceeded on {$scope}.");
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
            $this->recordActivity($quota, 'upgrade_recommended', "quota:{$quota->metric->key}", null);
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
        $reason = $this->quotaReason($quota);
        foreach ($this->targetInstanceIds($quota) as $instanceId) {
            $state = $this->state->current($instanceId);
            if ($state->status === CoreServiceStatusEnum::Active) {
                $message = 'A usage limit was exceeded. The service will be suspended after the grace period.';
                $this->state->set(
                    CoreServiceStatusEnum::Grace,
                    $reason,
                    $message,
                    CarbonImmutable::now()->addHours((int) config('core.quotas.grace_hours', 72)),
                    $instanceId,
                );
                $this->recordActivity($quota, 'grace', $reason, $message, $instanceId);
            } elseif ($state->status === CoreServiceStatusEnum::Grace
                && $this->isQuotaReason($state->reason)
                && $state->effectiveStatus() === CoreServiceStatusEnum::Suspended) {
                $activeReason = $state->reason ?? $reason;
                $this->state->set(CoreServiceStatusEnum::Suspended, $activeReason, 'Usage grace period ended.', null, $instanceId);
                $this->recordActivity($quota, 'suspend', $activeReason, null, $instanceId);
            }
        }
    }

    private function healSuspension(CommanderQuotaModel $quota): void
    {
        foreach ($this->targetInstanceIds($quota) as $instanceId) {
            $state = $this->state->current($instanceId);
            if (! $this->hasAnotherExceededQuota($quota, $instanceId)
                && $state->status !== CoreServiceStatusEnum::Active
                && $this->isQuotaReason($state->reason)) {
                $this->state->set(CoreServiceStatusEnum::Active, instanceId: $instanceId);
                $this->recordActivity($quota, 'resume', null, null, $instanceId);
            }
        }
    }

    private function hasAnotherExceededQuota(CommanderQuotaModel $quota, int $instanceId): bool
    {
        $subscriptionId = CommanderSubscriptionItemModel::query()
            ->where('instance_id', $instanceId)
            ->where('status', 'active')
            ->value('subscription_id');

        return CommanderQuotaModel::query()
            ->where('id', '!=', $quota->id)
            ->where('action', 'suspend')
            ->whereNotNull('limit_value')
            ->whereColumn('current_value', '>', 'limit_value')
            ->where(function ($query) use ($instanceId, $subscriptionId): void {
                $query->where('instance_id', $instanceId);
                if ($subscriptionId !== null) {
                    $query->orWhere('subscription_id', $subscriptionId);
                }
            })
            ->exists();
    }

    /** @return list<int> */
    private function targetInstanceIds(CommanderQuotaModel $quota): array
    {
        if ($quota->instance_id !== null) {
            return [(int) $quota->instance_id];
        }
        if ($quota->subscription_id === null) {
            return [];
        }

        return CommanderSubscriptionItemModel::query()
            ->where('subscription_id', $quota->subscription_id)
            ->where('status', 'active')
            ->whereHas('instance', fn ($query) => $query->where('provisioning_status', 'ready'))
            ->pluck('instance_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function quotaReason(CommanderQuotaModel $quota): string
    {
        return $quota->subscription_id !== null
            ? "shared_quota:{$quota->subscription_id}:{$quota->metric->key}"
            : "quota_exceeded:{$quota->metric->key}";
    }

    private function isQuotaReason(?string $reason): bool
    {
        return $reason !== null
            && (str_starts_with($reason, 'quota_exceeded:') || str_starts_with($reason, 'shared_quota:'));
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

    private function recordActivity(
        CommanderQuotaModel $quota,
        string $kind,
        ?string $reason,
        ?string $message,
        ?int $instanceId = null,
    ): void {
        CommanderActivityEventModel::create([
            'instance_id' => $instanceId ?? $quota->instance_id,
            'subscription_id' => $quota->subscription_id,
            'invoice_id' => null,
            'kind' => $kind,
            'reason' => $reason,
            'message' => $message,
        ]);
    }
}
