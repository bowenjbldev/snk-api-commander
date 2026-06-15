<?php

namespace Packages\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotaModel extends Model
{
    protected $table = 'cms_quota_metrics';

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
