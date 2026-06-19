<?php

namespace Packages\Core\Models;

use Illuminate\Database\Eloquent\Model;

class CoreQuotaMetricModel extends Model
{
    protected $table = 'quota_metrics';

    protected $connection = 'central';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
            'exceeded_at' => 'datetime',
        ];
    }

    public function isOverLimit(): bool
    {
        return $this->limit_value !== null && $this->current_value > $this->limit_value;
    }
}
