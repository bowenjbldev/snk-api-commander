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
        $instanceId ??= $this->active->id();
        $raw = Cache::remember(
            $this->cacheKey($instanceId),
            (int) config('core.state.cache_ttl', 30),
            function () use ($instanceId): array {
                $row = $instanceId !== null ? CommanderInstanceModel::query()->find($instanceId) : null;

                return [
                    'status' => $row?->getRawOriginal('service_status') ?? CoreServiceStatusEnum::Active->value,
                    'reason' => $row?->reason,
                    'message' => $row?->message,
                    'grace_until' => $row?->grace_until?->toIso8601String(),
                    'changed_at' => $row?->changed_at?->toIso8601String(),
                ];
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

        CommanderInstanceModel::query()->whereKey($instanceId)->update([
            'service_status' => $status->value,
            'reason' => $reason,
            'message' => $message,
            'grace_until' => $status === CoreServiceStatusEnum::Grace ? $graceUntil : null,
            'changed_at' => CarbonImmutable::now(),
        ]);
        Cache::forget($this->cacheKey($instanceId));

        return $this->current($instanceId);
    }

    private function cacheKey(?int $instanceId): string
    {
        return 'core:instance_state:'.($instanceId ?? 'none');
    }
}
