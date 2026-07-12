<?php

namespace Packages\Snk\Folio\Models;

use Illuminate\Database\Eloquent\Model;

class SnkFolioSettingModel extends Model
{
    protected $table = 'folio_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // Bolsa del workspace: {theme, theme_overrides, tenant, onboarded}; el controller la expone plana.
            'settings' => 'array',
        ];
    }
}
