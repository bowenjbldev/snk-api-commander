<?php

namespace Packages\Core\Models;

use Illuminate\Database\Eloquent\Model;

class CoreUsageRollupModel extends Model
{
    protected $table = 'core_usage_rollups';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
        ];
    }
}
