<?php

namespace Packages\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionModel extends Model
{
    protected $table = 'cms_subscriptions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'float',
            'auto_suspend' => 'boolean',
            'started_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ClientModel::class, 'client_id');
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(DeploymentModel::class, 'deployment_id');
    }
}
