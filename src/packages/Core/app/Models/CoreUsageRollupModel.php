<?php

namespace Packages\Core\Models;

use Illuminate\Database\Eloquent\Model;

class CoreUsageRollupModel extends Model
{
    protected $table = 'usage_rollups';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
        ];
    }
}
