<?php

namespace Packages\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Packages\Core\Database\Seeders\CorePackagesDatabaseSeeder;
use Packages\Core\Support\CorePackageDiscoverySupport;
use Packages\Core\Support\CorePackageSelectorSupport;

/**
 * Igual que core:migrate: --client + --package -> TENANT (Core + compartido + overlay);
 * sin ambos -> COMMANDER (operador: seeders de Core + Commander).
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

            $discovery = CorePackageDiscoverySupport::fromEnvironment(base_path('packages'));
            $modules = $discovery->implementationModules($client, $package);
            if ($modules === []) {
                $this->fail("No existe código compartido ni específico para [{$client}/{$package}].");
            }

            $connection = 'tenant';
            config([
                'database.connections.tenant.database' => CorePackageSelectorSupport::validatedDatabaseIdentifier(
                    CorePackageSelectorSupport::databaseName($client, $package)
                ),
                'database.connections.tenant.username' => config('database.connections.provisioner.username'),
                'database.connections.tenant.password' => config('database.connections.provisioner.password'),
            ]);
            DB::purge('tenant');
            $target = $modules;
        } else {
            $connection = 'commander';
            $target = 'Commander'; // el operador vive en la commander
        }

        $args = ['--force' => (bool) $this->option('force'), '--database' => $connection];

        if ($this->option('class')) {
            $this->info("Running seeder [{$this->option('class')}]...");

            return Artisan::call('db:seed', $args + ['--class' => $this->option('class')], $this->output);
        }

        CorePackagesDatabaseSeeder::$targetPackage = $target;

        $label = is_array($target) ? implode(' + ', $target) : $target;
        $this->info("Running seeders for [{$label}] on [{$connection}]...");
        try {
            return Artisan::call('db:seed', $args, $this->output);
        } finally {
            CorePackagesDatabaseSeeder::$targetPackage = null;
        }
    }
}
