<?php

namespace Packages\Commander\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Commander\Models\CommanderSubscriptionModel;

class CommanderProvisionPackageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $packageId,
        public readonly string $clientSlug,
        public readonly string $packageName,
        public readonly ?int $createdClientId,
    ) {
        $this->onQueue('provisioning');
    }

    public function handle(): void
    {
        $package = CommanderPackageModel::findOrFail($this->packageId);

        try {
            if (DB::connection('provisioner')->getDriverName() !== 'sqlite') {
                $this->createDatabaseAndUser($package);
                $this->runTenantSetup($package);
                $this->grantRuntimeAccess($package);
            }

            $package->forceFill(['status' => 'active', 'provisioned_at' => now()])->save();
        } catch (\Throwable $e) {
            $this->rollback($package);

            throw $e;
        } finally {
            DB::purge('tenant');
        }
    }

    private function createDatabaseAndUser(CommanderPackageModel $package): void
    {
        $db = $package->database;
        $user = $package->database_username;
        $password = $package->database_password;
        $connection = DB::connection('provisioner');

        $connection->statement("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        // Valores generados internamente: tenant_<PK> y Str::random(), sin entrada del usuario.
        $connection->statement("CREATE USER '{$user}'@'%' IDENTIFIED BY '{$password}'");
    }

    private function runTenantSetup(CommanderPackageModel $package): void
    {
        config([
            'database.connections.tenant.database' => $package->database,
            'database.connections.tenant.username' => config('database.connections.provisioner.username'),
            'database.connections.tenant.password' => config('database.connections.provisioner.password'),
        ]);
        DB::purge('tenant');

        $args = ['--client' => $this->clientSlug, '--package' => $this->packageName, '--force' => true];
        if (Artisan::call('core:migrate', $args) !== 0) {
            throw new \RuntimeException("Migration failed for package '{$this->clientSlug}/{$this->packageName}'.");
        }
        if (Artisan::call('core:seed', $args) !== 0) {
            throw new \RuntimeException("Seeding failed for package '{$this->clientSlug}/{$this->packageName}'.");
        }
    }

    private function grantRuntimeAccess(CommanderPackageModel $package): void
    {
        DB::connection('provisioner')->statement(
            "GRANT SELECT, INSERT, UPDATE, DELETE ON `{$package->database}`.* TO '{$package->database_username}'@'%'"
        );
    }

    private function rollback(CommanderPackageModel $package): void
    {
        try {
            if (DB::connection('provisioner')->getDriverName() !== 'sqlite') {
                DB::connection('provisioner')->statement("DROP DATABASE IF EXISTS `{$package->database}`");
                DB::connection('provisioner')->statement("DROP USER IF EXISTS '{$package->database_username}'@'%'");
            }
        } catch (\Throwable $cleanupError) {
            report($cleanupError);
        }

        DB::connection('commander')->transaction(function () use ($package): void {
            CommanderQuotaModel::query()->where('package_id', $package->id)->delete();
            CommanderSubscriptionModel::query()->where('package_id', $package->id)->delete();
            $package->delete();

            if ($this->createdClientId !== null) {
                CommanderClientModel::query()->whereKey($this->createdClientId)->delete();
            }
        });
    }
}
