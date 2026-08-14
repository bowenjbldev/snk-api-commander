<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommanderApplicationActivityDailyModel extends Model
{
    protected $table = 'commander_application_activity_daily';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'application_id' => 'integer',
            'day' => 'date',
            'commit_count' => 'integer',
            'additions' => 'integer',
            'deletions' => 'integer',
            'files_changed' => 'integer',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(CommanderApplicationModel::class, 'application_id');
    }
}
