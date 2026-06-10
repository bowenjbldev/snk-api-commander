<?php

namespace Packages\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Packages\Core\Console\Concerns\CorePackageDatabaseSupport;

class CoreMigrateCommand extends Command
{
    use CorePackageDatabaseSupport;

    protected $signature = 'core:migrate
        {--package= : Target package (defaults to API_PACKAGE, e.g. Shop)}
        {--seed : Run seeders after migrating}
        {--fresh : Drop all tables and re-run all migrations}
        {--rollback : Rollback the last batch of migrations}
        {--step= : Number of migrations to rollback}
        {--force : Force the operation to run in production}';

    protected $description = 'Run Core + package migrations against the default database connection';

    public function handle(): int
    {
        $package = $this->resolvePackageForConsole();

        $paths = [
            'packages/Core/database/migrations',
            "packages/{$package}/database/migrations",
        ];

        if ($this->option('rollback')) {
            return $this->runRollback($paths);
        }

        if ($this->option('fresh')) {
            return $this->runFresh($paths);
        }

        return $this->runMigrate($paths, $package);
    }

    private function runMigrate(array $paths, string $package): int
    {
        $args = [
            '--path' => $paths,
            '--force' => $this->option('force'),
        ];

        $this->info("Running migrations for [{$package}]...");
        Artisan::call('migrate', $args, $this->output);

        if ($this->option('seed')) {
            $this->info("Running seeders for [{$package}]...");
            Artisan::call('core:seed', [
                '--package' => $package,
                '--force' => $this->option('force'),
            ], $this->output);
        }

        return self::SUCCESS;
    }

    private function runFresh(array $paths): int
    {
        $args = [
            '--path' => $paths,
            '--force' => $this->option('force'),
        ];

        Artisan::call('migrate:fresh', $args, $this->output);

        return self::SUCCESS;
    }

    private function runRollback(array $paths): int
    {
        $args = [
            '--path' => $paths,
            '--force' => $this->option('force'),
        ];

        if ($this->option('step')) {
            $args['--step'] = (int) $this->option('step');
        }

        Artisan::call('migrate:rollback', $args, $this->output);

        return self::SUCCESS;
    }
}
