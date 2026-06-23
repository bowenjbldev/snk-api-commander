<?php

namespace Packages\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packages\Core\Database\Seeders\CorePackagesDatabaseSeeder;
use Packages\Core\Support\CorePackageSelectorSupport;

/**
 * Igual que core:migrate: --client + --package -> TENANT (package anidado, BD
 * snake(client)_snake(package)); sin ambos -> COMMANDER (operador: seeders de Core + Commander).
 */
class CoreSeedCommand extends Command
{
    protected $signature = 'core:seed
        {--client= : Cliente (slug) del TENANT. Requiere --package.}
        {--package= : Package del TENANT (StudlyCase). Requiere --client. Sin ambos = COMMANDER.}
        {--class= : Specific seeder class to run}
        {--force : Force the operation to run in production}';

    protected $description = 'Run seeders on the commander database or on a package\'s tenant database';

    public function handle(): int
    {
        $client = trim((string) $this->option('client')) ?: null;
        $package = trim((string) $this->option('package')) ?: null;

        if ($client !== null || $package !== null) {
            if ($client === null || $package === null) {
                $this->fail('TENANT requiere --client Y --package. Sin ambos => commander.');
            }

            $clientStudly = Str::studly($client);
            $packageStudly = Str::studly($package);

            if (! is_dir(base_path("packages/{$clientStudly}/{$packageStudly}"))) {
                $this->fail("Package [{$clientStudly}/{$packageStudly}] no existe en packages/.");
            }

            $connection = 'tenant';
            config(['database.connections.tenant.database' => CorePackageSelectorSupport::databaseName($client, $package)]);
            DB::purge('tenant');
            $target = "{$clientStudly}\\{$packageStudly}"; // namespace del módulo: Acme\Shop
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
}
