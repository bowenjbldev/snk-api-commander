<?php

namespace Packages\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packages\Core\Support\CorePackageSelectorSupport;

/**
 * Multi-tenant, cliente-primero. --client + --package deciden el destino:
 *   - SIN ambos        -> COMMANDER (BD commander): registro + metering + operador + base operador.
 *   - CON --client + --package -> TENANT: package anidado packages/Studly(client)/Studly(package);
 *     BD derivada snake(client)_snake(package) (base Laravel del tenant + tablas de negocio).
 * El provisioning usa core:migrate --client=acme --package=Shop; el deploy usa core:migrate.
 */
class CoreMigrateCommand extends Command
{
    protected $signature = 'core:migrate
        {--client= : Cliente (slug) del TENANT. Requiere --package. Deriva la BD snake(client)_snake(package).}
        {--package= : Package de negocio del TENANT (StudlyCase). Requiere --client.}
        {--seed : Run seeders after migrating}
        {--fresh : Drop all tables and re-run all migrations}
        {--rollback : Rollback the last batch of migrations}
        {--step= : Number of migrations to rollback}
        {--force : Force the operation to run in production}';

    protected $description = 'Run migrations on the commander database or on a package\'s tenant database';

    private const COMMANDER_PATHS = [
        'packages/Core/database/migrations',           // base Laravel del operador (compartida con tenants)
        'packages/Commander/database/migrations',      // registro (packages) + commander + commander_*
    ];

    public function handle(): int
    {
        $client = trim((string) $this->option('client')) ?: null;
        $package = trim((string) $this->option('package')) ?: null;

        if ($client !== null || $package !== null) {
            // TENANT: package anidado packages/Studly(client)/Studly(package); exige ambos.
            if ($client === null || $package === null) {
                $this->fail('TENANT requiere --client Y --package (la BD se deriva snake(client)_snake(package)). Sin ambos => commander.');
            }

            $clientStudly = Str::studly($client);
            $packageStudly = Str::studly($package);
            $dir = "packages/{$clientStudly}/{$packageStudly}";

            if (! is_dir(base_path($dir))) {
                $this->fail("Package [{$clientStudly}/{$packageStudly}] no existe en packages/.");
            }

            $connection = 'tenant';
            config(['database.connections.tenant.database' => CorePackageSelectorSupport::databaseName($client, $package)]);
            DB::purge('tenant');

            $paths = [
                'packages/Core/database/migrations',
                "{$dir}/database/migrations",
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

        $this->info("Running migrations on [{$connection}]" . ($package ? " for [{$client}/{$package}]" : '') . '...');
        Artisan::call('migrate', $args, $this->output);

        if ($this->option('seed')) {
            $seedArgs = ['--force' => (bool) $this->option('force')];
            if ($package !== null) {
                $seedArgs['--client'] = $client;
                $seedArgs['--package'] = $package;
            }
            Artisan::call('core:seed', $seedArgs, $this->output);
        }

        return self::SUCCESS;
    }
}
