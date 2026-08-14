<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommanderApplicationCommitModel extends Model
{
    protected $table = 'commander_application_commits';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'application_id' => 'integer',
            'committed_at' => 'datetime',
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
