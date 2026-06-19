<?php

namespace Packages\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packages\Core\Database\Seeders\CorePackagesDatabaseSeeder;

/**
 * Igual que core:migrate: --package -> TENANT (BD = snake(package)); sin --package -> COMMANDER
 * (operador: seeders de Core + Commander en la BD commander).
 */
class CoreSeedCommand extends Command
{
    protected $signature = 'core:seed
        {--package= : Package -> TENANT (BD = snake(package)). Sin --package = COMMANDER (operador).}
        {--class= : Specific seeder class to run}
        {--force : Force the operation to run in production}';

    protected $description = 'Run seeders on the commander database or on a package\'s tenant database';

    public function handle(): int
    {
        $package = $this->resolvePackage();

        if ($package !== null) {
            $connection = 'tenant';
            config(['database.connections.tenant.database' => Str::snake($package)]);
            DB::purge('tenant');
            $target = $package;
        } else {
            $connection = 'commander';
            $target = 'Commander'; // el operador vive en la commander
        }

        $args = ['--force' => (bool) $this->option('force'), '--database' => $connection];

        if ($this->option('class')) {
            $this->info("Running seeder [{$this->option('class')}]...");
            Artisan::call('db:seed', $args + ['--class' => $this->option('class')], $this->output);

            return self::SUCCESS;
        }

        CorePackagesDatabaseSeeder::$targetPackage = $target;

        $this->info("Running seeders for [{$target}] on [{$connection}]...");
        Artisan::call('db:seed', $args, $this->output);

        CorePackagesDatabaseSeeder::$targetPackage = null;

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
