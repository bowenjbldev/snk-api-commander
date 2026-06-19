<?php

namespace Packages\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Multi-tenant. La presencia de --package decide el destino (convencion package = BD):
 *   - SIN --package  -> COMMANDER (BD commander): registro + control-plane + commander_* + base operador.
 *   - CON --package   -> TENANT (BD = snake(package)): base Laravel del tenant + tablas de negocio.
 * El provisioning usa `core:migrate --package=Shop`; el deploy usa `core:migrate` (commander).
 */
class CoreMigrateCommand extends Command
{
    protected $signature = 'core:migrate
        {--package= : Package de negocio -> migracion de TENANT (BD = snake(package)). Sin --package = COMMANDER.}
        {--seed : Run seeders after migrating}
        {--fresh : Drop all tables and re-run all migrations}
        {--rollback : Rollback the last batch of migrations}
        {--step= : Number of migrations to rollback}
        {--force : Force the operation to run in production}';

    protected $description = 'Run migrations on the commander database or on a package\'s tenant database';

    private const COMMANDER_PATHS = [
        'packages/Core/database/migrations',           // base Laravel del operador (compartida con tenants)
        'packages/Commander/database/migrations',      // registro (packages) + control-plane + commander_*
    ];

    public function handle(): int
    {
        $package = $this->resolvePackage();

        if ($package !== null) {
            // TENANT: apunta la conexion a la BD del package (snake) antes de migrar.
            $connection = 'tenant';
            config(['database.connections.tenant.database' => Str::snake($package)]);
            DB::purge('tenant');

            $paths = [
                'packages/Core/database/migrations',
                "packages/{$package}/database/migrations",
            ];
        } else {
            $connection = 'commander';
            $paths = self::COMMANDER_PATHS;
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
            $seedArgs = ['--force' => (bool) $this->option('force')];
            if ($package !== null) {
                $seedArgs['--package'] = $package;
            }
            Artisan::call('core:seed', $seedArgs, $this->output);
        }

        return self::SUCCESS;
    }

    /** --package validado (StudlyCase + existe en disco), o null = commander. */
    private function resolvePackage(): ?string
    {
        $raw = trim((string) $this->option('package'));

        if ($raw === '') {
            return null;
        }

        $package = Str::studly($raw);

        if (! is_dir(base_path("packages/{$package}"))) {
            $this->fail("Package [{$package}] no existe en packages/.");
        }

        return $package;
    }
}
