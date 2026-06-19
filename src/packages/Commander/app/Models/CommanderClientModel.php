<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommanderClientModel extends Model
{
    protected $table = 'commander_clients';

    protected $connection = 'commander';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function deployments(): HasMany
    {
        return $this->hasMany(CommanderDeploymentModel::class, 'client_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(CommanderSubscriptionModel::class, 'client_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CommanderInvoiceModel::class, 'client_id');
    }
}
