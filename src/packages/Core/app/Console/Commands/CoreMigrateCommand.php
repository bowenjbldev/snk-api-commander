<?php

namespace Packages\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Core\Support\CorePackageDiscoverySupport;
use Packages\Core\Support\CorePackageSelectorSupport;

/**
 * Multi-tenant, cliente-primero. --client + --package deciden el destino:
 *   - SIN ambos        -> COMMANDER (BD commander): registro + metering + operador + base operador.
 *   - CON --client + --package -> TENANT: Core + package compartido + overlay opcional;
 *     BD derivada snake(client)_snake(package).
 * El provisioning usa core:migrate --client=acme --package=Shop; el deploy usa core:migrate.
 */
class CoreMigrateCommand extends Command
{
    protected $signature = 'core:migrate
        {--client= : Cliente (slug) del TENANT. Requiere --package. Deriva la BD snake(client)_snake(package).}
        {--package= : Package de negocio del TENANT (StudlyCase). Requiere --client.}
        {--all-tenants : Migrate every provisioned tenant after a deploy.}
        {--seed : Run seeders after migrating}
        {--fresh : Drop all tables and re-run all migrations}
        {--rollback : Rollback the last batch of migrations}
        {--step= : Number of migrations to rollback}
        {--force : Force the operation to run in production}';

    protected $description = 'Run migrations on the commander database or on a package\'s tenant database';

    public function handle(): int
    {
        $client = trim((string) $this->option('client')) ?: null;
        $package = trim((string) $this->option('package')) ?: null;
        $allTenants = (bool) $this->option('all-tenants');

        if ($allTenants) {
            if ($client !== null || $package !== null || $this->option('seed') || $this->option('fresh') || $this->option('rollback')) {
                $this->fail('--all-tenants no se combina con --client, --package, --seed, --fresh ni --rollback.');
            }

            return $this->migrateAllTenants();
        }

        if ($client !== null || $package !== null) {
            if ($client === null || $package === null) {
                $this->fail('TENANT requiere --client Y --package (la BD se deriva snake(client)_snake(package)). Sin ambos => commander.');
            }

            $discovery = $this->discovery();
            if (! $discovery->hasImplementation($client, $package)) {
                $this->fail("No existe código compartido ni específico para [{$client}/{$package}].");
            }

            $connection = 'tenant';
            $this->configureTenant(CorePackageSelectorSupport::databaseName($client, $package));
            $paths = $this->tenantMigrationPaths($discovery, $client, $package);
        } else {
            $connection = 'commander';
            $paths = [
                base_path('packages/Core/database/migrations'),
                base_path('packages/Commander/database/migrations'),
            ];
        }

        $args = [
            '--path' => $paths,
            '--realpath' => true,
            '--database' => $connection, // nombre de CONEXION para artisan migrate
            '--force' => (bool) $this->option('force'),
        ];

        if ($this->option('rollback')) {
            if ($this->option('step')) {
                $args['--step'] = (int) $this->option('step');
            }

            return Artisan::call('migrate:rollback', $args, $this->output);
        }

        if ($this->option('fresh')) {
            return Artisan::call('migrate:fresh', $args, $this->output);
        }

        $this->info("Running migrations on [{$connection}]".($package ? " for [{$client}/{$package}]" : '').'...');
        $status = Artisan::call('migrate', $args, $this->output);

        if ($status !== self::SUCCESS) {
            return $status;
        }

        if ($this->option('seed')) {
            $seedArgs = ['--force' => (bool) $this->option('force')];
            if ($package !== null) {
                $seedArgs['--client'] = $client;
                $seedArgs['--package'] = $package;
            }

            return Artisan::call('core:seed', $seedArgs, $this->output);
        }

        return $status;
    }

    private function migrateAllTenants(): int
    {
        $packages = CommanderPackageModel::query()
            ->provisioned()
            ->with('client:id,slug')
            ->orderBy('id')
            ->get();
        $discovery = $this->discovery();

        // Preflight completo antes del primer DDL: un registro roto no deja el despliegue a medias.
        foreach ($packages as $package) {
            if (! $package->client?->slug || ! $package->name || ! $package->database
                || ! $package->database_username || ! $package->database_password) {
                $this->error("Tenant [{$package->id}] tiene un registro de provisioning incompleto.");

                return self::FAILURE;
            }
            try {
                CorePackageSelectorSupport::validatedDatabaseIdentifier($package->database);
            } catch (\RuntimeException $e) {
                $this->error("Tenant [{$package->id}]: {$e->getMessage()}");

                return self::FAILURE;
            }
            if (! $discovery->hasImplementation($package->client->slug, $package->name)) {
                $this->error("Tenant [{$package->id}] no tiene código compartido ni específico desplegado.");

                return self::FAILURE;
            }
        }

        foreach ($packages as $package) {
            $client = $package->client->slug;
            $this->configureTenant($package->database);
            $this->info("Migrating tenant [{$client}/{$package->name}]...");
            $status = Artisan::call('migrate', [
                '--path' => $this->tenantMigrationPaths($discovery, $client, $package->name),
                '--realpath' => true,
                '--database' => 'tenant',
                '--force' => (bool) $this->option('force'),
            ], $this->output);

            if ($status !== self::SUCCESS) {
                return $status;
            }
        }

        return self::SUCCESS;
    }

    /** @return string[] */
    private function tenantMigrationPaths(CorePackageDiscoverySupport $discovery, string $client, string $package): array
    {
        $paths = [base_path('packages/Core/database/migrations')];

        foreach ($discovery->implementationDirectories($client, $package) as $directory) {
            if (is_dir("{$directory}/database/migrations")) {
                $paths[] = "{$directory}/database/migrations";
            }
        }

        return $paths;
    }

    private function configureTenant(string $database): void
    {
        config([
            'database.connections.tenant.database' => CorePackageSelectorSupport::validatedDatabaseIdentifier($database),
            'database.connections.tenant.username' => config('database.connections.provisioner.username'),
            'database.connections.tenant.password' => config('database.connections.provisioner.password'),
        ]);
        DB::purge('tenant');
    }

    private function discovery(): CorePackageDiscoverySupport
    {
        return CorePackageDiscoverySupport::fromEnvironment(base_path('packages'));
    }
}
