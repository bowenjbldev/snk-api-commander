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
            // Bolsa de preferencias schemaless; el controller valida cada clave y la expone plana.
            'settings' => 'array',
        ];
    }
}
