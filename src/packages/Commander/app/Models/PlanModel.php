<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;

class PlanModel extends Model
{
    protected $table = 'commander_plans';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'float',
            'q_requests_day' => 'integer',
            'q_disk_db_mb' => 'integer',
            'q_disk_storage_mb' => 'integer',
            'q_users_total' => 'integer',
        ];
    }

    /** The four quota limits keyed by the deploy's metric_key (null = unlimited). */
    public function quotaLimits(): array
    {
        return [
            'requests_day' => $this->q_requests_day,
            'disk_db_mb' => $this->q_disk_db_mb,
            'disk_storage_mb' => $this->q_disk_storage_mb,
            'users_total' => $this->q_users_total,
        ];
    }
}
