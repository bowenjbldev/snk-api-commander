<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Queue\Console\TableCommand;
use Packages\Core\Console\Concerns\CorePackageAwareMigrationGeneratorSupport;

class CoreQueueTableMakeCommand extends TableCommand
{
    use CorePackageAwareMigrationGeneratorSupport;
}
