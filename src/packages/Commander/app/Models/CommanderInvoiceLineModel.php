<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommanderInvoiceLineModel extends Model
{
    protected $table = 'commander_invoice_lines';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'invoice_id' => 'integer',
            'subscription_id' => 'integer',
            'subscription_item_id' => 'integer',
            'quantity' => 'integer',
            'unit_amount' => 'float',
            'amount' => 'float',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CommanderInvoiceModel::class, 'invoice_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CommanderSubscriptionModel::class, 'subscription_id');
    }

    public function subscriptionItem(): BelongsTo
    {
        return $this->belongsTo(CommanderSubscriptionItemModel::class, 'subscription_item_id');
    }
}
