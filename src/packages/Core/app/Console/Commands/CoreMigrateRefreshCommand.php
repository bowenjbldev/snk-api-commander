<?php

namespace Packages\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Packages\Core\Console\Concerns\CorePackageDatabaseSupport;
use Packages\Core\Support\CorePackageSelectorSupport;

class CoreMigrateRefreshCommand extends Command
{
    use CorePackageDatabaseSupport;

    protected $signature = 'core:migrate:refresh
        {--client= : Cliente del paquete (required, e.g. acme)}
        {--package= : Paquete dentro del cliente (required, e.g. Shop)}
        {--seed : Run seeders after refreshing}
        {--force : Force the operation to run in production}';

    protected $description = 'Reset and re-run all Core + package migrations on a nested package tenant DB';

    public function handle(): int
    {
        [$client, $package] = $this->resolvePackageForConsole();

        // TENANT: apunta la conexión a la BD del paquete anidado ({client}_{package}) antes de refrescar.
        config([
            'database.connections.tenant.database' => CorePackageSelectorSupport::databaseName($client, $package),
            'database.connections.tenant.username' => config('database.connections.provisioner.username'),
            'database.connections.tenant.password' => config('database.connections.provisioner.password'),
        ]);
        DB::purge('tenant');

        $paths = [
            'packages/Core/database/migrations',
            "packages/{$client}/{$package}/database/migrations",
        ];

        $args = [
            '--path' => $paths,
            '--database' => 'tenant',
            '--force' => $this->option('force'),
        ];

        $this->info("Refreshing migrations for [{$client}/{$package}]...");
        Artisan::call('migrate:refresh', $args, $this->output);

        if ($this->option('seed')) {
            $this->info("Running seeders for [{$client}/{$package}]...");
            Artisan::call('core:seed', [
                '--client' => $client,
                '--package' => $package,
                '--force' => $this->option('force'),
            ], $this->output);
        }

        return self::SUCCESS;
    }
}
