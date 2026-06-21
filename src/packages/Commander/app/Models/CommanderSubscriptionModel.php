<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un término de subscripción de un package (1:N = historial de planes). El vigente es el de
 * `ended_at IS NULL`; los cerrados son historial. `plan`/`price_monthly` son snapshots congelados;
 * `plan_id` es solo trazabilidad al catálogo.
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
            'client_id' => 'integer',
            'package_id' => 'integer',
            'plan_id' => 'integer',
            'price_monthly' => 'float',
            'auto_suspend' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** Solo los términos vigentes (sin cerrar). */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(CommanderClientModel::class, 'client_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(CommanderPackageModel::class, 'package_id');
    }

    /** Catálogo del que salió este término (trazabilidad; los snapshots mandan en billing). */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(CommanderPlanModel::class, 'plan_id');
    }
}
