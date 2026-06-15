<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceModel extends Model
{
    protected $table = 'commander_invoices';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ClientModel::class, 'client_id');
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(DeploymentModel::class, 'deployment_id');
    }
}
