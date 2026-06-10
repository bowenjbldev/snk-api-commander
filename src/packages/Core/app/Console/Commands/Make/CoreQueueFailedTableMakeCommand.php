<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Queue\Console\FailedTableCommand;
use Packages\Core\Console\Concerns\CorePackageAwareMigrationGeneratorSupport;

class CoreQueueFailedTableMakeCommand extends FailedTableCommand
{
    use CorePackageAwareMigrationGeneratorSupport;
}
