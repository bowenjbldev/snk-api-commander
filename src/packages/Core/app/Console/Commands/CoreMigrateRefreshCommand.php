<?php

namespace Packages\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Packages\Core\Console\Concerns\CorePackageDatabaseSupport;

class CoreMigrateRefreshCommand extends Command
{
    use CorePackageDatabaseSupport;

    protected $signature = 'core:migrate:refresh
        {--package= : Target package (defaults to API_PACKAGE, e.g. Shop)}
        {--seed : Run seeders after refreshing}
        {--force : Force the operation to run in production}';

    protected $description = 'Reset and re-run all Core + package migrations on the default connection';

    public function handle(): int
    {
        $package = $this->resolvePackageForConsole();

        $paths = [
            'packages/Core/database/migrations',
            "packages/{$package}/database/migrations",
        ];

        $args = [
            '--path' => $paths,
            '--force' => $this->option('force'),
        ];

        $this->info("Refreshing migrations for [{$package}]...");
        Artisan::call('migrate:refresh', $args, $this->output);

        if ($this->option('seed')) {
            $this->info("Running seeders for [{$package}]...");
            Artisan::call('core:seed', [
                '--package' => $package,
                '--force' => $this->option('force'),
            ], $this->output);
        }

        return self::SUCCESS;
    }
}
