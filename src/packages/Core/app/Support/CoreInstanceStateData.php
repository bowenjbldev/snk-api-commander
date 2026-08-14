<?php

namespace Packages\Core\Support;

use Carbon\CarbonImmutable;
use Packages\Core\Enums\CoreServiceStatusEnum;

final readonly class CoreInstanceStateData
{
    public function __construct(
        public CoreServiceStatusEnum $status,
        public ?string $reason,
        public ?string $message,
        public ?CarbonImmutable $graceUntil,
        public ?CarbonImmutable $changedAt = null,
    ) {}

    /**
     * Estado efectivo: un grace con fecha vencida se comporta como suspended
     * sin necesidad de escribir en BD desde la ruta de lectura.
     */
    public function effectiveStatus(): CoreServiceStatusEnum
    {
        if ($this->status === CoreServiceStatusEnum::Grace
            && $this->graceUntil !== null
            && $this->graceUntil->isPast()) {
            return CoreServiceStatusEnum::Suspended;
        }

        return $this->status;
    }
}
