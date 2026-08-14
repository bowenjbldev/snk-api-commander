<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommanderPlanModel extends Model
{
    protected $table = 'commander_plans';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'application_id' => 'integer',
            'price_monthly' => 'float',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(CommanderApplicationModel::class, 'application_id');
    }

    public function limits(): HasMany
    {
        return $this->hasMany(CommanderPlanLimitModel::class, 'plan_id');
    }

    public function subscriptionItems(): HasMany
    {
        return $this->hasMany(CommanderSubscriptionItemModel::class, 'plan_id');
    }
}
