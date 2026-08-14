<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Packages\Core\Enums\CoreServiceStatusEnum;

class CommanderInstanceModel extends Model
{
    protected $table = 'commander_instances';

    protected $connection = 'commander';

    protected $guarded = [];

    protected $hidden = ['database_username', 'database_password'];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'client_id' => 'integer',
            'application_id' => 'integer',
            'service_status' => CoreServiceStatusEnum::class,
            'database_password' => 'encrypted',
            'grace_until' => 'datetime',
            'changed_at' => 'datetime',
            'last_seen' => 'datetime',
            'requested_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'deprovisioned_at' => 'datetime',
            'requests_24h' => 'integer',
            'quota_health' => 'float',
        ];
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('provisioning_status', 'ready');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(CommanderClientModel::class, 'client_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(CommanderApplicationModel::class, 'application_id');
    }

    public function subscriptionItem(): HasOne
    {
        return $this->hasOne(CommanderSubscriptionItemModel::class, 'instance_id');
    }

    public function quotas(): HasMany
    {
        return $this->hasMany(CommanderQuotaModel::class, 'instance_id');
    }
}
