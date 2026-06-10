<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Queue\Console\BatchesTableCommand;
use Packages\Core\Console\Concerns\CorePackageAwareMigrationGeneratorSupport;

class CoreQueueBatchesTableMakeCommand extends BatchesTableCommand
{
    use CorePackageAwareMigrationGeneratorSupport;
}
