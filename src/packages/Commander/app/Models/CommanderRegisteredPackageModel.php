<?php

namespace Packages\Commander\Models;

use Illuminate\Database\Eloquent\Model;

class CommanderRegisteredPackageModel extends Model
{
    protected $table = 'commander_registered_packages';

    protected $connection = 'commander';

    protected $guarded = [];
}
