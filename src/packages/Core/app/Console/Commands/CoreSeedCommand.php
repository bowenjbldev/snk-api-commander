<?php

namespace Packages\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Packages\Core\Console\Concerns\CorePackageDatabaseSupport;
use Packages\Core\Database\Seeders\CorePackagesDatabaseSeeder;

class CoreSeedCommand extends Command
{
    use CorePackageDatabaseSupport;

    protected $signature = 'core:seed
        {--package= : Target package (defaults to API_PACKAGE, e.g. Shop)}
        {--class= : Specific seeder class to run}
        {--force : Force the operation to run in production}';

    protected $description = 'Run Core + package seeders against the default database connection';

    public function handle(): int
    {
        $package = $this->resolvePackageForConsole();

        if ($this->option('class')) {
            $this->info("Running seeder [{$this->option('class')}]...");
            Artisan::call('db:seed', [
                '--class' => $this->option('class'),
                '--force' => $this->option('force'),
            ], $this->output);

            return self::SUCCESS;
        }

        CorePackagesDatabaseSeeder::$targetPackage = $package;

        $this->info("Running seeders for [{$package}]...");
        Artisan::call('db:seed', [
            '--force' => $this->option('force'),
        ], $this->output);

        CorePackagesDatabaseSeeder::$targetPackage = null;

        return self::SUCCESS;
    }
}
