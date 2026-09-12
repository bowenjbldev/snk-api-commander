<?php

namespace Packages\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Core\Support\CorePackageDiscoverySupport;
use Packages\Core\Support\CorePackageSelectorSupport;

/**
 * Multi-tenant, cliente-primero. --client + --package deciden el destino:
 *   - SIN ambos        -> COMMANDER (BD commander): registro + metering + operador + base operador.
 *   - CON --client + --package -> TENANT: Core + package compartido + overlay opcional;
 *     BD derivada snake(client)_snake(package).
 * El provisioning usa core:migrate --client=acme --package=Shop; el deploy usa core:migrate.
 *
 * `--all-tenants --fresh [--seed]` vacía y rehace la base de cada periódico: es para empezar de
 * cero en local, y en producción no hay flag que lo abra. Sin `--fresh` no siembra, porque un
 * despliegue no vuelve a sembrar.
 */
class CoreMigrateCommand extends Command
{
    protected $signature = 'core:migrate
        {--client= : Tenant client (slug). Requires --package. The database is derived as snake(client)_snake(package).}
        {--package= : Tenant business package (StudlyCase). Requires --client.}
        {--all-tenants : Migrate every provisioned tenant after a deploy. With --fresh (never in production), rebuild them all.}
        {--seed : Run seeders after migrating. With --all-tenants, only together with --fresh.}
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
            if ($client !== null || $package !== null || $this->option('rollback')) {
                $this->fail('--all-tenants cannot be combined with --client, --package or --rollback.');
            }
            if ($this->option('seed') && ! $this->option('fresh')) {
                $this->fail('--all-tenants only seeds with --fresh: a deploy does not seed again.');
            }
            // Vaciar todos los periódicos de una vez es de desarrollo: un deploy con el flag de
            // más no puede borrar la redacción de nadie.
            if ($this->option('fresh') && app()->isProduction()) {
                $this->fail('--all-tenants --fresh wipes every newspaper and cannot run in production.');
            }

            return $this->migrateAllTenants();
        }

        if ($client !== null || $package !== null) {
            if ($client === null || $package === null) {
                $this->fail('TENANT requires --client AND --package (the database is derived as snake(client)_snake(package)). Without both => commander.');
            }

            $discovery = $this->discovery();
            if (! $discovery->hasImplementation($client, $package)) {
                $this->fail("No shared or client-specific implementation exists for [{$client}/{$package}].");
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
            '--database' => $connection, // nombre de CONEXIÓN para artisan migrate
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
        $instances = CommanderInstanceModel::query()
            ->ready()
            ->with(['client:id,slug', 'application:id,package_name'])
            ->orderBy('id')
            ->get();
        $discovery = $this->discovery();

        // Preflight completo antes del primer DDL: un registro roto no deja el despliegue a medias.
        foreach ($instances as $instance) {
            if (! $instance->client?->slug || ! $instance->application?->package_name || ! $instance->database
                || ! $instance->database_username || ! $instance->database_password) {
                $this->error("Tenant [{$instance->id}] has an incomplete provisioning record.");

                return self::FAILURE;
            }
            try {
                CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database);
            } catch (\RuntimeException $e) {
                $this->error("Tenant [{$instance->id}]: {$e->getMessage()}");

                return self::FAILURE;
            }
            if (! $discovery->hasImplementation($instance->client->slug, $instance->application->package_name)) {
                $this->error("Tenant [{$instance->id}] has no shared or client-specific implementation deployed.");

                return self::FAILURE;
            }
        }

        $fresh = (bool) $this->option('fresh');
        foreach ($instances as $instance) {
            $client = $instance->client->slug;
            $package = $instance->application->package_name;
            $this->configureTenant($instance->database);
            $this->info(($fresh ? 'Rebuilding' : 'Migrating')." tenant [{$client}/{$package}]...");
            $status = Artisan::call($fresh ? 'migrate:fresh' : 'migrate', [
                '--path' => $this->tenantMigrationPaths($discovery, $client, $package),
                '--realpath' => true,
                '--database' => 'tenant',
                '--force' => (bool) $this->option('force'),
            ], $this->output);

            if ($status !== self::SUCCESS) {
                return $status;
            }

            if ($this->option('seed')) {
                $status = Artisan::call('core:seed', [
                    '--client' => $client,
                    '--package' => $package,
                    '--force' => (bool) $this->option('force'),
                ], $this->output);

                if ($status !== self::SUCCESS) {
                    return $status;
                }
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
