<?php

namespace Packages\Core\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Core\Enums\CorePackageStatusEnum;
use Packages\Core\Support\CoreActivePackageSupport;
use Packages\Core\Support\CorePackageStateData;

/**
 * Estado runtime por package (active|grace|suspended), guardado como columnas de la fila
 * `commander_packages`. El package activo se resuelve del request (CoreActivePackageSupport)
 * o se pasa explicito (Commander, medidor de cuotas por package). Lee/escribe via
 * CommanderPackageModel. Los lectores usan current(), que cachea la fila por core.state.cache_ttl.
 */
class CorePackageStateService
{
    public function __construct(private readonly CoreActivePackageSupport $active)
    {
    }

    public function current(?int $packageId = null): CorePackageStateData
    {
        $packageId ??= $this->active->id();

        $raw = Cache::remember(
            $this->cacheKey($packageId),
            (int) config('core.state.cache_ttl', 30),
            function () use ($packageId): array {
                $row = $packageId !== null ? CommanderPackageModel::query()->find($packageId) : null;

                return [
                    'status' => $row?->getRawOriginal('status') ?? CorePackageStatusEnum::Active->value,
                    'reason' => $row?->reason,
                    'message' => $row?->message,
                    'grace_until' => $row?->grace_until?->toIso8601String(),
                    'changed_by' => $row?->changed_by,
                    'changed_at' => $row?->changed_at?->toIso8601String(),
                ];
            }
        );

        return new CorePackageStateData(
            status: CorePackageStatusEnum::tryFrom($raw['status']) ?? CorePackageStatusEnum::Active,
            reason: $raw['reason'],
            message: $raw['message'],
            graceUntil: $raw['grace_until'] !== null ? CarbonImmutable::parse($raw['grace_until']) : null,
            changedBy: $raw['changed_by'],
            changedAt: $raw['changed_at'] !== null ? CarbonImmutable::parse($raw['changed_at']) : null,
        );
    }

    public function set(
        CorePackageStatusEnum $status,
        ?string $reason = null,
        ?string $message = null,
        ?CarbonInterface $graceUntil = null,
        string $changedBy = 'cli',
        ?int $packageId = null,
    ): CorePackageStateData {
        $packageId ??= $this->active->id();

        if ($packageId === null) {
            // Sin package provisionado en contexto (p.ej. host del operador): nada que persistir.
            return $this->current(null);
        }

        CommanderPackageModel::query()->whereKey($packageId)->update([
            'status' => $status->value,
            'reason' => $reason,
            'message' => $message,
            'grace_until' => $status === CorePackageStatusEnum::Grace ? $graceUntil : null,
            'changed_by' => $changedBy,
            'changed_at' => CarbonImmutable::now(),
        ]);

        Cache::forget($this->cacheKey($packageId));

        return $this->current($packageId);
    }

    private function cacheKey(?int $packageId): string
    {
        return 'core:package_state:'.($packageId ?? 'none');
    }
}
