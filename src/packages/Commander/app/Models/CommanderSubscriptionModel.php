<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommanderSubscriptionModel extends Model
{
    protected $table = 'commander_subscriptions';

    protected $connection = 'commander';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'float',
            'auto_suspend' => 'boolean',
            'started_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(CommanderClientModel::class, 'client_id');
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(CommanderDeploymentModel::class, 'deployment_id');
    }
}
