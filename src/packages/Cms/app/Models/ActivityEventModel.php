<?php

namespace Packages\Cms\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityEventModel extends Model
{
    protected $table = 'cms_activity_events';

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
