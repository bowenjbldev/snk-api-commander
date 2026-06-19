<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommanderUsageSnapshotModel extends Model
{
    protected $table = 'commander_usage_snapshots';

    protected $connection = 'commander';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'request_count' => 'integer',
            'error_count' => 'integer',
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(CommanderDeploymentModel::class, 'deployment_id');
    }
}
