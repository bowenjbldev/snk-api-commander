<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Packages\Core\Enums\CorePackageStatusEnum;

/**
 * Tabla unica de packages de negocio. Una fila por package en disco: catalogo (label/description/
 * listing_status) SIEMPRE presente; columnas operativas (subdomain/database/client/estado/KPIs) +
 * `provisioned_at` solo cuando esta provisionado (la instancia viva 1:1). El de-provision las anula.
 */
class CommanderPackageModel extends Model
{
    protected $table = 'commander_packages';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'client_id' => 'integer',
            'subscription_id' => 'integer',
            'status' => CorePackageStatusEnum::class,
            'grace_until' => 'datetime',
            'changed_at' => 'datetime',
            'last_seen' => 'datetime',
            'provisioned_at' => 'datetime',
            'requests_24h' => 'integer',
            'quota_health' => 'float',
        ];
    }

    /** Solo las filas con instancia viva (provisionadas); las solo-catalogo quedan fuera. */
    public function scopeProvisioned(Builder $query): Builder
    {
        return $query->whereNotNull('provisioned_at');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(CommanderClientModel::class, 'client_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CommanderSubscriptionModel::class, 'subscription_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(CommanderSubscriptionModel::class, 'package_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CommanderInvoiceModel::class, 'package_id');
    }

    public function quotas(): HasMany
    {
        return $this->hasMany(CommanderQuotaModel::class, 'package_id');
    }
}
