<?php

namespace Packages\Core\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Packages\Core\Enums\CorePackageStatusEnum;
use Packages\Core\Support\CoreActivePackageSupport;
use Packages\Core\Support\CorePackageStateData;

/**
 * Estado runtime por package (active|grace|suspended), guardado como columnas de la fila
 * `packages` (BD commander). El package activo se resuelve del request (CoreActivePackageSupport)
 * o se pasa explicito (Commander, medidor de cuotas por package). Lee/escribe por query builder
 * sobre la conexion 'commander': el modelo del registro vive en Commander y Core no lo importa.
 * Los lectores usan current(), que cachea la fila por core.state.cache_ttl.
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
                $row = $packageId !== null
                    ? DB::connection('commander')->table('packages')->where('id', $packageId)->first()
                    : null;

                return [
                    'status' => $row->status ?? CorePackageStatusEnum::Active->value,
                    'reason' => $row->reason ?? null,
                    'message' => $row->message ?? null,
                    'grace_until' => $row->grace_until ?? null,
                    'changed_by' => $row->changed_by ?? null,
                    'changed_at' => $row->changed_at ?? null,
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

        DB::connection('commander')->table('packages')->where('id', $packageId)->update([
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
