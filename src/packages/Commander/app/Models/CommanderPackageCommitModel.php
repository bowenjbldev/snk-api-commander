<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommanderPackageCommitModel extends Model
{
    protected $table = 'commander_package_commits';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'package_id' => 'integer',
            'committed_at' => 'datetime',
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
