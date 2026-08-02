<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommanderAccountModel extends Model
{
    protected $table = 'commander_accounts';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['id' => 'integer'];
    }

    public function clients(): HasMany
    {
        return $this->hasMany(CommanderClientModel::class, 'account_id');
    }
}
