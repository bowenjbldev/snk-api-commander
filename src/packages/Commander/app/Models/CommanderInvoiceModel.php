<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommanderInvoiceModel extends Model
{
    protected $table = 'commander_invoices';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'client_id' => 'integer',
            'deployment_id' => 'integer',
            'amount' => 'float',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(CommanderClientModel::class, 'client_id');
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(CommanderDeploymentModel::class, 'deployment_id');
    }
}
