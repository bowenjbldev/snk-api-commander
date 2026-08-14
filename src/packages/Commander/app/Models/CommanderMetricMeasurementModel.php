<?php

namespace Packages\Commander\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommanderMetricMeasurementModel extends Model
{
    protected $table = 'commander_metric_measurements';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'quota_id' => 'integer',
            'bucket_date' => 'date',
            'value' => 'integer',
            'measured_at' => 'datetime',
        ];
    }

    protected function bucketDate(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => CarbonImmutable::parse($value)->toDateString(),
        );
    }

    public function quota(): BelongsTo
    {
        return $this->belongsTo(CommanderQuotaModel::class, 'quota_id');
    }
}
