<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityEventModel extends Model
{
    protected $table = 'commander_activity_events';

    protected $connection = 'central';

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
