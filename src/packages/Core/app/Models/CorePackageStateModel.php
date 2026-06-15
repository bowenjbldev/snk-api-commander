<?php

namespace Packages\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Packages\Core\Enums\CorePackageStatusEnum;

class CorePackageStateModel extends Model
{
    protected $table = 'core_package_state';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => CorePackageStatusEnum::class,
            'grace_until' => 'datetime',
            'control_secret' => 'encrypted',
        ];
    }
}
