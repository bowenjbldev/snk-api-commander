<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommanderQuotaModel extends Model
{
    protected $table = 'commander_quotas';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'deployment_id' => 'integer',
            'current_value' => 'integer',
            'limit_value' => 'integer',
            'exceeded_at' => 'datetime',
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(CommanderDeploymentModel::class, 'deployment_id');
    }
}
