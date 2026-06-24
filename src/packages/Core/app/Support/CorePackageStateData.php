<?php

namespace Packages\Core\Support;

use Carbon\CarbonImmutable;
use Packages\Core\Enums\CorePackageStatusEnum;

final readonly class CorePackageStateData
{
    public function __construct(
        public CorePackageStatusEnum $status,
        public ?string $reason,
        public ?string $message,
        public ?CarbonImmutable $graceUntil,
        public ?CarbonImmutable $changedAt = null,
    ) {
    }

    /**
     * Estado efectivo: un grace con fecha vencida se comporta como suspended
     * sin necesidad de escribir en BD desde la ruta de lectura.
     */
    public function effectiveStatus(): CorePackageStatusEnum
    {
        if ($this->status === CorePackageStatusEnum::Grace
            && $this->graceUntil !== null
            && $this->graceUntil->isPast()) {
            return CorePackageStatusEnum::Suspended;
        }

        return $this->status;
    }
}
