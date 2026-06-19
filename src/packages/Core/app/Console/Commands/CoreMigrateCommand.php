<?php

namespace Packages\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packages\Core\Console\Concerns\CorePackageDatabaseSupport;

/**
 * Multi-tenant: migra sobre la conexion CENTRAL (registro + control-plane + commander + base del
 * operador) o sobre la conexion TENANT de un package (su base Laravel + sus tablas de negocio,
 * en su propia BD). El provisioning usa --connection=tenant --db=<bd> --package=<Pkg>.
 */
class CoreMigrateCommand extends Command
{
    use CorePackageDatabaseSupport;

    protected $signature = 'core:migrate
        {--connection=central : Conexion destino: central | tenant}
        {--package= : Package de negocio para las migraciones de tenant (ej. Shop)}
        {--db= : Nombre de la BD del tenant (por defecto snake(package); convencion package = BD)}
        {--seed : Run seeders after migrating}
        {--fresh : Drop all tables and re-run all migrations}
        {--rollback : Rollback the last batch of migrations}
        {--step= : Number of migrations to rollback}
        {--force : Force the operation to run in production}';

    protected $description = 'Run Core + package migrations on the central or a tenant database';

    private const CENTRAL_PATHS = [
        'packages/Core/database/migrations/central',   // core_packages + control-plane
        'packages/Core/database/migrations/tenant',    // base Laravel del operador
        'packages/Commander/database/migrations',      // commander_*
    ];

    public function handle(): int
    {
        $connection = (string) ($this->option('connection') ?: 'central');
        $package = null;

        if ($connection === 'tenant') {
            // Convencion: package = BD. --db solo si quieres un nombre distinto al de snake(package).
            $package = $this->resolvePackageForConsole();
            $db = trim((string) $this->option('db')) ?: Str::snake($package);

            // Apunta la conexion tenant a la BD del package antes de migrar.
            config(['database.connections.tenant.database' => $db]);
            DB::purge('tenant');

            $paths = [
                'packages/Core/database/migrations/tenant',          // base Laravel del tenant
                "packages/{$package}/database/migrations",            // negocio del package
            ];
        } else {
            $paths = self::CENTRAL_PATHS;
        }

        $args = [
            '--path' => $paths,
            '--database' => $connection, // nombre de CONEXION para artisan migrate
            '--force' => (bool) $this->option('force'),
        ];

        if ($this->option('rollback')) {
            if ($this->option('step')) {
                $args['--step'] = (int) $this->option('step');
            }
            Artisan::call('migrate:rollback', $args, $this->output);

            return self::SUCCESS;
        }

        if ($this->option('fresh')) {
            Artisan::call('migrate:fresh', $args, $this->output);

            return self::SUCCESS;
        }

        $this->info("Running migrations on [{$connection}]" . ($package ? " for [{$package}]" : '') . '...');
        Artisan::call('migrate', $args, $this->output);

        if ($this->option('seed')) {
            Artisan::call('core:seed', [
                '--connection' => $connection,
                '--package' => $package ?? 'Commander',
                '--force' => (bool) $this->option('force'),
            ], $this->output);
        }

        return self::SUCCESS;
    }
}
