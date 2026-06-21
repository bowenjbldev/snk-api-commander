<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommanderClientModel extends Model
{
    protected $table = 'commander_clients';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['id' => 'integer'];
    }

    public function packages(): HasMany
    {
        return $this->hasMany(CommanderPackageModel::class, 'client_id');
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
