<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;

class CommanderActivityEventModel extends Model
{
    protected $table = 'commander_activity_events';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'deployment_id' => 'integer',
            'invoice_id' => 'integer',
            'at' => 'datetime',
        ];
    }
}
