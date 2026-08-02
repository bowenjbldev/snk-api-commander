<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommanderClientModel extends Model
{
    protected $table = 'commander_clients';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['id' => 'integer', 'account_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $client): void {
            if ($client->account_id !== null) {
                return;
            }

            $client->account_id = CommanderAccountModel::create([
                'name' => $client->company ?: $client->name,
                'email' => $client->email,
                'billing_email' => $client->billing_email,
                'phone' => $client->phone,
                'tax_id' => $client->tax_id,
                'payment_terms' => $client->payment_terms,
                'address_line1' => $client->address_line1,
                'address_line2' => $client->address_line2,
                'city' => $client->city,
                'postal_code' => $client->postal_code,
                'country' => $client->country,
                'notes' => $client->notes,
            ])->id;
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CommanderAccountModel::class, 'account_id');
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
