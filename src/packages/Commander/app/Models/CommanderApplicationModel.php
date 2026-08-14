<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CommanderApplicationModel extends Model
{
    protected $table = 'commander_applications';

    protected $connection = 'commander';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['id' => 'integer', 'last_synced_at' => 'datetime'];
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(CommanderApplicationMetricModel::class, 'application_id');
    }

    public function plans(): HasMany
    {
        return $this->hasMany(CommanderPlanModel::class, 'application_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(CommanderInstanceModel::class, 'application_id');
    }

    public function repo(): HasOne
    {
        return $this->hasOne(CommanderApplicationRepoModel::class, 'application_id');
    }

    public function commits(): HasMany
    {
        return $this->hasMany(CommanderApplicationCommitModel::class, 'application_id');
    }

    public function activityDaily(): HasMany
    {
        return $this->hasMany(CommanderApplicationActivityDailyModel::class, 'application_id');
    }
}
