<?php

namespace Packages\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Packages\Core\Console\Concerns\CorePackageDatabaseSupport;
use Packages\Core\Support\CorePackageDiscoverySupport;
use Packages\Core\Support\CorePackageSelectorSupport;

class CoreMigrateRefreshCommand extends Command
{
    use CorePackageDatabaseSupport;

    protected $signature = 'core:migrate:refresh
        {--client= : Package client (required, e.g. acme)}
        {--package= : Package inside the client (required, e.g. Shop)}
        {--seed : Run seeders after refreshing}
        {--force : Force the operation to run in production}';

    protected $description = 'Reset and re-run Core + shared + overlay migrations on a tenant DB';

    public function handle(): int
    {
        [$client, $package] = $this->resolvePackageForConsole();

        // TENANT: apunta la conexión a la BD aislada ({client}_{package}) antes de refrescar.
        config([
            'database.connections.tenant.database' => CorePackageSelectorSupport::validatedDatabaseIdentifier(
                CorePackageSelectorSupport::databaseName($client, $package)
            ),
            'database.connections.tenant.username' => config('database.connections.provisioner.username'),
            'database.connections.tenant.password' => config('database.connections.provisioner.password'),
        ]);
        DB::purge('tenant');

        $discovery = CorePackageDiscoverySupport::fromEnvironment(base_path('packages'));
        $paths = [base_path('packages/Core/database/migrations')];
        foreach ($discovery->implementationDirectories($client, $package) as $directory) {
            if (is_dir("{$directory}/database/migrations")) {
                $paths[] = "{$directory}/database/migrations";
            }
        }

        $args = [
            '--path' => $paths,
            '--realpath' => true,
            '--database' => 'tenant',
            '--force' => $this->option('force'),
        ];

        $this->info("Refreshing migrations for [{$client}/{$package}]...");
        $status = Artisan::call('migrate:refresh', $args, $this->output);

        if ($status !== self::SUCCESS) {
            return $status;
        }

        if ($this->option('seed')) {
            $this->info("Running seeders for [{$client}/{$package}]...");

            return Artisan::call('core:seed', [
                '--client' => $client,
                '--package' => $package,
                '--force' => $this->option('force'),
            ], $this->output);
        }

        return $status;
    }
}
