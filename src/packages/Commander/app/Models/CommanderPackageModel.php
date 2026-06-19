<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Packages\Core\Enums\CorePackageStatusEnum;

/**
 * Registro de un package provisionado (packages). Identidad (name/subdomain) +
 * estado runtime (status/reason/grace_until...).
 */
class CommanderPackageModel extends Model
{
    protected $table = 'commander_packages';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => CorePackageStatusEnum::class,
            'grace_until' => 'datetime',
            'changed_at' => 'datetime',
        ];
    }
}
