<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotaModel extends Model
{
    protected $table = 'commander_quota_metrics';

    protected $connection = 'central';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'current_value' => 'integer',
            'limit_value' => 'integer',
            'exceeded_at' => 'datetime',
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(DeploymentModel::class, 'deployment_id');
    }
}
