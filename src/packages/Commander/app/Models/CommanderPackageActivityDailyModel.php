<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommanderPackageActivityDailyModel extends Model
{
    protected $table = 'commander_package_activity_daily';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'package_id' => 'integer',
            'day' => 'date',
            'commit_count' => 'integer',
            'additions' => 'integer',
            'deletions' => 'integer',
            'files_changed' => 'integer',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(CommanderPackageModel::class, 'package_id');
    }
}
