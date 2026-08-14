<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommanderQuotaModel extends Model
{
    protected $table = 'commander_quotas';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'instance_id' => 'integer',
            'metric_id' => 'integer',
            'current_value' => 'integer',
            'limit_value' => 'integer',
            'warning_percent' => 'integer',
            'consecutive_periods' => 'integer',
            'consecutive_exceeded' => 'integer',
            'threshold_reached_at' => 'datetime',
            'exceeded_at' => 'datetime',
            'upgrade_recommended_at' => 'datetime',
            'measured_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(CommanderInstanceModel::class, 'instance_id');
    }

    public function metric(): BelongsTo
    {
        return $this->belongsTo(CommanderApplicationMetricModel::class, 'metric_id');
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(CommanderMetricMeasurementModel::class, 'quota_id');
    }

    public function isOverLimit(): bool
    {
        return $this->limit_value !== null && $this->current_value > $this->limit_value;
    }
}
