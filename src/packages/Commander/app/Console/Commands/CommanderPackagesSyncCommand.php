<?php

namespace Packages\Commander\Console\Commands;

use Illuminate\Console\Command;
use Packages\Commander\Services\CommanderPackageRegistryService;

class CommanderPackagesSyncCommand extends Command
{
    protected $signature = 'commander:packages:sync';

    protected $description = 'Sincroniza el registro de packages desde packages/* (disco, via git).';

    public function handle(CommanderPackageRegistryService $registry): int
    {
        $created = $registry->syncFromDisk();

        $this->info("Sync de packages: {$created} registrado(s) nuevo(s) desde disco.");

        return self::SUCCESS;
    }
}
