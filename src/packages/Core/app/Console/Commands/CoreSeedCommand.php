<?php

namespace Packages\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packages\Core\Console\Concerns\CorePackageDatabaseSupport;
use Packages\Core\Database\Seeders\CorePackagesDatabaseSeeder;

class CoreSeedCommand extends Command
{
    use CorePackageDatabaseSupport;

    protected $signature = 'core:seed
        {--connection= : Conexion destino (central|tenant); por defecto la default de la app}
        {--package= : Target package (defaults to API_PACKAGE, e.g. Shop)}
        {--db= : Nombre de la BD del tenant (por defecto snake(package); convencion package = BD)}
        {--class= : Specific seeder class to run}
        {--force : Force the operation to run in production}';

    protected $description = 'Run Core + package seeders on the central or a tenant database';

    public function handle(): int
    {
        $package = $this->resolvePackageForConsole();
        $connection = $this->option('connection');

        if ($connection === 'tenant') {
            // Convencion: package = BD. --db solo para un nombre distinto al de snake(package).
            $db = trim((string) $this->option('db')) ?: Str::snake($package);
            config(['database.connections.tenant.database' => $db]);
            DB::purge('tenant');
        }

        $args = ['--force' => (bool) $this->option('force')];
        if ($connection) {
            $args['--database'] = $connection; // db:seed corre los seeders sobre esta conexion
        }

        if ($this->option('class')) {
            $this->info("Running seeder [{$this->option('class')}]...");
            Artisan::call('db:seed', $args + ['--class' => $this->option('class')], $this->output);

            return self::SUCCESS;
        }

        CorePackagesDatabaseSeeder::$targetPackage = $package;

        $this->info("Running seeders for [{$package}]...");
        Artisan::call('db:seed', $args, $this->output);

        CorePackagesDatabaseSeeder::$targetPackage = null;

        return self::SUCCESS;
    }
}
