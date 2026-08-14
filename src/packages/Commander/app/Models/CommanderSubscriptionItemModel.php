<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommanderSubscriptionItemModel extends Model
{
    protected $table = 'commander_subscription_items';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'subscription_id' => 'integer',
            'instance_id' => 'integer',
            'plan_id' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CommanderSubscriptionModel::class, 'subscription_id');
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(CommanderInstanceModel::class, 'instance_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CommanderPlanModel::class, 'plan_id');
    }
}
