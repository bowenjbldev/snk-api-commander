<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Session\Console\SessionTableCommand;
use Packages\Core\Console\Concerns\CorePackageAwareMigrationGeneratorSupport;

class CoreSessionTableMakeCommand extends SessionTableCommand
{
    use CorePackageAwareMigrationGeneratorSupport;
}
