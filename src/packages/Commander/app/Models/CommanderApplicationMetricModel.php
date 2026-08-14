<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommanderApplicationMetricModel extends Model
{
    protected $table = 'commander_application_metrics';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['id' => 'integer', 'application_id' => 'integer'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(CommanderApplicationModel::class, 'application_id');
    }

    public function planLimits(): HasMany
    {
        return $this->hasMany(CommanderPlanLimitModel::class, 'metric_id');
    }
}
