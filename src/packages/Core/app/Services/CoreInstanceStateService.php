<?php

namespace Packages\Core\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Core\Enums\CoreServiceStatusEnum;
use Packages\Core\Support\CoreActiveInstanceSupport;
use Packages\Core\Support\CoreInstanceStateData;

class CoreInstanceStateService
{
    public function __construct(private readonly CoreActiveInstanceSupport $active) {}

    public function current(?int $instanceId = null): CoreInstanceStateData
    {
        // HTTP already resolved this row from Commander. Explicit IDs (CLI, quotas and set)
        // keep their lookup semantics, even when another instance is active.
        $resolved = $instanceId === null ? $this->active->current() : null;
        $instanceId ??= $this->active->id();
        $raw = $resolved !== null ? $this->stateAttributes($resolved) : Cache::remember(
            $this->cacheKey($instanceId),
            (int) config('core.state.cache_ttl', 30),
            function () use ($instanceId): array {
                $row = $instanceId !== null ? CommanderInstanceModel::query()->find($instanceId) : null;

                return $this->stateAttributes($row);
            },
        );

        return new CoreInstanceStateData(
            status: CoreServiceStatusEnum::tryFrom($raw['status']) ?? CoreServiceStatusEnum::Active,
            reason: $raw['reason'],
            message: $raw['message'],
            graceUntil: $raw['grace_until'] !== null ? CarbonImmutable::parse($raw['grace_until']) : null,
            changedAt: $raw['changed_at'] !== null ? CarbonImmutable::parse($raw['changed_at']) : null,
        );
    }

    private function stateAttributes(?CommanderInstanceModel $row): array
    {
        return [
            'status' => $row?->service_status?->value ?? CoreServiceStatusEnum::Active->value,
            'reason' => $row?->reason,
            'message' => $row?->message,
            'grace_until' => $row?->grace_until?->toIso8601String(),
            'changed_at' => $row?->changed_at?->toIso8601String(),
        ];
    }

    public function set(
        CoreServiceStatusEnum $status,
        ?string $reason = null,
        ?string $message = null,
        ?CarbonInterface $graceUntil = null,
        ?int $instanceId = null,
    ): CoreInstanceStateData {
        $instanceId ??= $this->active->id();
        if ($instanceId === null) {
            return $this->current(null);
        }

        $attributes = [
            'service_status' => $status->value,
            'reason' => $reason,
            'message' => $message,
            'grace_until' => $status === CoreServiceStatusEnum::Grace ? $graceUntil : null,
            'changed_at' => CarbonImmutable::now(),
        ];
        CommanderInstanceModel::query()->whereKey($instanceId)->update($attributes);
        if ($this->active->id() === $instanceId) {
            $this->active->current()->forceFill($attributes);
        }
        Cache::forget($this->cacheKey($instanceId));

        return $this->current($instanceId);
    }

    private function cacheKey(?int $instanceId): string
    {
        return 'core:instance_state:'.($instanceId ?? 'none');
    }
}
