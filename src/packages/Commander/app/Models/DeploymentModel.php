<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Packages\Commander\Models\PackageModel;

class DeploymentModel extends Model
{
    protected $table = 'commander_deployments';

    protected $connection = 'commander';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = [
        'package_id',
        'core_package', // relacion interna (estado); el SPA usa `package` (string) y `state`
    ];

    // SPA shape: nested `state` object, ahora leido de packages (fuente de verdad).
    protected $appends = [
        'state',
    ];

    protected function casts(): array
    {
        return [
            'last_seen' => 'datetime',
            'requests_24h' => 'integer',
            'quota_health' => 'float',
        ];
    }

    /** Estado runtime del package (packages), con la forma DeploymentState del SPA. */
    public function getStateAttribute(): array
    {
        $package = $this->relationLoaded('corePackage')
            ? $this->getRelation('corePackage')
            : ($this->package_id !== null ? $this->corePackage()->first() : null);

        return [
            'status' => $package?->getRawOriginal('status') ?? 'active',
            'reason' => $package?->reason,
            'message' => $package?->message,
            'grace_until' => optional($package?->grace_until)->toISOString(),
            'changed_by' => $package?->changed_by,
            'changed_at' => optional($package?->changed_at)->toISOString(),
        ];
    }

    /** Relacion con la instancia/estado (packages). Nombre distinto de la columna `package`. */
    public function corePackage(): BelongsTo
    {
        return $this->belongsTo(PackageModel::class, 'package_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ClientModel::class, 'client_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(SubscriptionModel::class, 'subscription_id');
    }

    public function quotas(): HasMany
    {
        return $this->hasMany(QuotaModel::class, 'deployment_id');
    }
}
