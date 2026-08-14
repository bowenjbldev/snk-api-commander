<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommanderPlanLimitModel extends Model
{
    protected $table = 'commander_plan_limits';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'plan_id' => 'integer',
            'metric_id' => 'integer',
            'limit_value' => 'integer',
            'warning_percent' => 'integer',
            'consecutive_periods' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CommanderPlanModel::class, 'plan_id');
    }

    public function metric(): BelongsTo
    {
        return $this->belongsTo(CommanderApplicationMetricModel::class, 'metric_id');
    }
}
