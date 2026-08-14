<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommanderInvoiceModel extends Model
{
    protected $table = 'commander_invoices';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'account_id' => 'integer',
            'total_amount' => 'float',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CommanderAccountModel::class, 'account_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CommanderInvoiceLineModel::class, 'invoice_id');
    }
}
