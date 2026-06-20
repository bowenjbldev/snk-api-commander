<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;

class CommanderRegisteredPackageModel extends Model
{
    protected $table = 'commander_registered_packages';

    protected $connection = 'commander';

    protected $primaryKey = 'name';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
