<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;

class SnkKilkanaSettingModel extends Model
{
    protected $table = 'kilkana_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // Bolsa del workspace: {theme, theme_overrides, tenant, onboarded}; el controller la expone plana.
            'settings' => 'array',
        ];
    }
}
