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

    public function account(): BelongsTo
    {
        return $this->belongsTo(CommanderAccountModel::class, 'account_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(CommanderInstanceModel::class, 'client_id');
    }
}
