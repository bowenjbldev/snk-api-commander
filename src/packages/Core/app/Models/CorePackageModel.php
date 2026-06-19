<?php

namespace Packages\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Packages\Core\Enums\CorePackageStatusEnum;

/**
 * Registro de un package provisionado (core_packages). Identidad (name/subdomain) +
 * estado runtime (status/reason/grace_until...). Reemplaza al singleton core_package_state.
 */
class CorePackageModel extends Model
{
    protected $table = 'core_packages';

    protected $connection = 'central';

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
