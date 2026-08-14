<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommanderActivityEventModel extends Model
{
    protected $table = 'commander_activity_events';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'instance_id' => 'integer',
            'subscription_id' => 'integer',
            'invoice_id' => 'integer',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(CommanderInstanceModel::class, 'instance_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CommanderInvoiceModel::class, 'invoice_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CommanderSubscriptionModel::class, 'subscription_id');
    }
}
