<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Packages\Core\Enums\CorePackageStatusEnum;

/**
 * Tabla única de productos e instancias. Las filas de catálogo no tienen cliente; cada alta crea
 * otra fila para el par (cliente, package), con BD, estado y `provisioned_at` al terminar el worker.
 */
class CommanderPackageModel extends Model
{
    protected $table = 'commander_packages';

    protected $connection = 'commander';

    protected $guarded = [];

    protected $hidden = ['database_username', 'database_password'];

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
            'database_password' => 'encrypted',
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

    /** Origen git (1:1) para los insights: de dónde se sincroniza + cursor. */
    public function repo(): HasOne
    {
        return $this->hasOne(CommanderPackageRepoModel::class, 'package_id');
    }

    /** Commits crudos sincronizados (fuente de los insights). */
    public function commits(): HasMany
    {
        return $this->hasMany(CommanderPackageCommitModel::class, 'package_id');
    }

    /** Rollup diario de actividad git (heatmap + code frequency). */
    public function activityDaily(): HasMany
    {
        return $this->hasMany(CommanderPackageActivityDailyModel::class, 'package_id');
    }
}
