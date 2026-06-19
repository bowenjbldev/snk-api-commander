<?php

namespace Packages\Core\Models;

use Illuminate\Database\Eloquent\Model;

class CoreUsageRollupModel extends Model
{
    protected $table = 'usage_rollups';

    protected $connection = 'central';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
        ];
    }
}
