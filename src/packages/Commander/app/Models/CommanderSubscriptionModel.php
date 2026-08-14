<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contrato comercial de una cuenta para una aplicación. Los ítems asignan planes a instancias.
 */
class CommanderSubscriptionModel extends Model
{
    protected $table = 'commander_subscriptions';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'account_id' => 'integer',
            'application_id' => 'integer',
            'monthly_amount' => 'float',
            'max_instances' => 'integer',
            'trial_ends_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CommanderAccountModel::class, 'account_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(CommanderApplicationModel::class, 'application_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CommanderSubscriptionItemModel::class, 'subscription_id');
    }

    public function quotas(): HasMany
    {
        return $this->hasMany(CommanderQuotaModel::class, 'subscription_id');
    }

    public function invoiceLines(): HasMany
    {
        return $this->hasMany(CommanderInvoiceLineModel::class, 'subscription_id');
    }
}
