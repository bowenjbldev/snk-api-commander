<?php

namespace Packages\Commander\Console\Commands;

use Illuminate\Console\Command;
use Packages\Commander\Services\CommanderApplicationRegistryService;

class CommanderApplicationsSyncCommand extends Command
{
    protected $signature = 'commander:applications:sync';

    protected $description = 'Synchronize deployed packages as Commander applications';

    public function handle(CommanderApplicationRegistryService $registry): int
    {
        $result = $registry->sync();
        $this->info("Applications: {$result['applications']}; metrics: {$result['metrics']}");

        return self::SUCCESS;
    }
}
