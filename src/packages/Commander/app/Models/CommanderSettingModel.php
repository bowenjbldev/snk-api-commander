<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;

class CommanderSettingModel extends Model
{
    protected $table = 'commander_settings';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'default_grace_hours' => 'integer',
            'notify_on_suspend' => 'boolean',
            'notify_on_overdue' => 'boolean',
        ];
    }
}
