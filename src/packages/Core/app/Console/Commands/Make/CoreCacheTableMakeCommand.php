<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Cache\Console\CacheTableCommand;
use Packages\Core\Console\Concerns\CorePackageAwareMigrationGeneratorSupport;

class CoreCacheTableMakeCommand extends CacheTableCommand
{
    use CorePackageAwareMigrationGeneratorSupport;
}
