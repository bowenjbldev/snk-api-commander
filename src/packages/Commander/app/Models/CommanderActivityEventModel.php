<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;

class CommanderActivityEventModel extends Model
{
    protected $table = 'commander_activity_events';

    protected $connection = 'commander';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'at' => 'datetime',
        ];
    }
}
