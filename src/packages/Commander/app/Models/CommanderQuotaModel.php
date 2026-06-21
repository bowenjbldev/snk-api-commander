<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommanderQuotaModel extends Model
{
    protected $table = 'commander_quotas';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'package_id' => 'integer',
            'current_value' => 'integer',
            'limit_value' => 'integer',
            'exceeded_at' => 'datetime',
            'measured_at' => 'datetime',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(CommanderPackageModel::class, 'package_id');
    }

    public function isOverLimit(): bool
    {
        return $this->limit_value !== null && $this->current_value > $this->limit_value;
    }
}
