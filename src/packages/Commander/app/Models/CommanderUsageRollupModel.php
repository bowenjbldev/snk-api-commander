<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;

class CommanderUsageRollupModel extends Model
{
    protected $table = 'commander_usage_rollups';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
        ];
    }
}
