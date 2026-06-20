<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommanderDeploymentModel extends Model
{
    protected $table = 'commander_deployments';

    protected $connection = 'commander';

    protected $guarded = [];

    // La identidad (name/subdomain/status...) NO se almacena ni se deriva con accessors: vive en
    // commander_packages y viaja como relacion anidada `package`, que se eager-carga SIEMPRE.
    // El front lee el dato de la relation (d.package.name, d.package.status, ...).
    protected $with = ['package'];

    // package_id es redundante con package.id (la relation va anidada); el resto del overlay es real.
    protected $hidden = ['package_id'];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'client_id' => 'integer',
            'subscription_id' => 'integer',
            'last_seen' => 'datetime',
            'requests_24h' => 'integer',
            'quota_health' => 'float',
        ];
    }

    /** Instancia/estado del package provisionado (commander_packages): fuente de verdad de la identidad. */
    public function package(): BelongsTo
    {
        return $this->belongsTo(CommanderPackageModel::class, 'package_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(CommanderClientModel::class, 'client_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CommanderSubscriptionModel::class, 'subscription_id');
    }

    public function quotas(): HasMany
    {
        return $this->hasMany(CommanderQuotaModel::class, 'deployment_id');
    }
}
