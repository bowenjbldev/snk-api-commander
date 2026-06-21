<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommanderSubscriptionModel extends Model
{
    protected $table = 'commander_subscriptions';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'client_id' => 'integer',
            'package_id' => 'integer',
            'price_monthly' => 'float',
            'auto_suspend' => 'boolean',
            'started_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(CommanderClientModel::class, 'client_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(CommanderPackageModel::class, 'package_id');
    }
}
