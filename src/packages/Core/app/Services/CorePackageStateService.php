<?php

namespace Packages\Core\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Packages\Core\Enums\CorePackageStatusEnum;
use Packages\Core\Models\CorePackageStateModel;
use Packages\Core\Support\CorePackageStateData;

/**
 * Unico propietario del estado del deploy. Todo lo que suspende o reactiva
 * (CLI, cuotas, Commander via /control) pasa por set(); los lectores usan current()
 * que cachea la fila durante core.state.cache_ttl segundos.
 */
class CorePackageStateService
{
    public function current(): CorePackageStateData
    {
        $raw = Cache::remember(
            (string) config('core.state.cache_key', 'core:package_state'),
            (int) config('core.state.cache_ttl', 30),
            function (): array {
                $row = CorePackageStateModel::query()->find(1);

                return [
                    'status' => $row?->getRawOriginal('status') ?? CorePackageStatusEnum::Active->value,
                    'reason' => $row?->reason,
                    'message' => $row?->message,
                    'grace_until' => $row?->grace_until?->toIso8601String(),
                    'changed_by' => $row?->changed_by,
                ];
            }
        );

        return new CorePackageStateData(
            status: CorePackageStatusEnum::tryFrom($raw['status']) ?? CorePackageStatusEnum::Active,
            reason: $raw['reason'],
            message: $raw['message'],
            graceUntil: $raw['grace_until'] !== null ? CarbonImmutable::parse($raw['grace_until']) : null,
            changedBy: $raw['changed_by'],
        );
    }

    public function set(
        CorePackageStatusEnum $status,
        ?string $reason = null,
        ?string $message = null,
        ?CarbonInterface $graceUntil = null,
        string $changedBy = 'cli',
    ): CorePackageStateData {
        CorePackageStateModel::query()->updateOrCreate(
            ['id' => 1],
            [
                'status' => $status,
                'reason' => $reason,
                'message' => $message,
                'grace_until' => $status === CorePackageStatusEnum::Grace ? $graceUntil : null,
                'changed_by' => $changedBy,
            ]
        );

        Cache::forget((string) config('core.state.cache_key', 'core:package_state'));

        return $this->current();
    }
}
