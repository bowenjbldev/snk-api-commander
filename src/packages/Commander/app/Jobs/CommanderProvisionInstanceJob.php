<?php

namespace Packages\Commander\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Core\Models\CoreUserModel;
use Packages\Core\Support\CorePackageSelectorSupport;

class CommanderProvisionInstanceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $instanceId,
        public readonly string $clientSlug,
        public readonly string $packageName,
        public readonly string $adminEmail,
        public readonly string $encryptedAdminPassword,
    ) {
        $this->onQueue('provisioning');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("tenant-instance:{$this->instanceId}"))->shared()->expireAfter($this->timeout + 60)];
    }

    public function handle(): void
    {
        $instance = CommanderInstanceModel::findOrFail($this->instanceId);
        if ($instance->provisioning_status !== 'pending') {
            return;
        }

        $originalConnection = DB::getDefaultConnection();
        $originalTenantConfig = config('database.connections.tenant');
        $originalAdminEmail = config('core.auth.admin_email');

        $instance->forceFill([
            'provisioning_status' => 'provisioning',
            'last_error' => null,
            'deprovisioned_at' => null,
        ])->save();

        try {
            if (DB::connection('provisioner')->getDriverName() !== 'sqlite') {
                $this->createDatabaseAndUser($instance);
                $this->runTenantSetup($instance);
                $this->grantRuntimeAccess($instance);
            }

            $instance->forceFill([
                'provisioning_status' => 'ready',
                'service_status' => 'active',
                'provisioned_at' => now(),
                'last_error' => null,
            ])->save();
        } catch (\Throwable $error) {
            $this->cleanupResources($instance);
            $instance->forceFill([
                'provisioning_status' => 'failed',
                'provisioned_at' => null,
                'last_error' => mb_substr($error->getMessage(), 0, 4000),
            ])->save();

            throw $error;
        } finally {
            config(['database.connections.tenant' => $originalTenantConfig]);
            config(['core.auth.admin_email' => $originalAdminEmail]);
            DB::setDefaultConnection($originalConnection);
            DB::purge('tenant');
        }
    }

    private function createDatabaseAndUser(CommanderInstanceModel $instance): void
    {
        $database = CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database);
        $username = CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database_username);
        $connection = DB::connection('provisioner');
        $quotedPassword = $connection->getPdo()->quote($instance->database_password);
        if ($quotedPassword === false) {
            throw new \RuntimeException('Could not quote the generated tenant database password.');
        }

        $connection->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $connection->unprepared("CREATE USER '{$username}'@'%' IDENTIFIED BY {$quotedPassword}");
    }

    private function runTenantSetup(CommanderInstanceModel $instance): void
    {
        config([
            'database.connections.tenant.database' => $instance->database,
            'database.connections.tenant.username' => config('database.connections.provisioner.username'),
            'database.connections.tenant.password' => config('database.connections.provisioner.password'),
        ]);
        DB::purge('tenant');

        $arguments = ['--client' => $this->clientSlug, '--package' => $this->packageName, '--force' => true];
        if (Artisan::call('core:migrate', $arguments) !== 0) {
            throw new \RuntimeException("Migration failed for [{$this->clientSlug}/{$this->packageName}].");
        }

        DB::setDefaultConnection('tenant');
        config(['core.auth.admin_email' => $this->adminEmail]);
        CoreUserModel::firstOrCreate(
            ['email' => $this->adminEmail],
            ['name' => 'Admin User', 'password' => Crypt::decryptString($this->encryptedAdminPassword)],
        );
        if (Artisan::call('core:seed', $arguments) !== 0) {
            throw new \RuntimeException("Seeding failed for [{$this->clientSlug}/{$this->packageName}].");
        }
        CoreUserModel::query()->where('email', $this->adminEmail)->firstOrFail()->assignRole('admin');
    }

    private function grantRuntimeAccess(CommanderInstanceModel $instance): void
    {
        DB::connection('provisioner')->statement(
            'GRANT SELECT, INSERT, UPDATE, DELETE ON `'.CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database)
            .'`.* TO \''.CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database_username).'\'@\'%\''
        );
    }

    private function cleanupResources(CommanderInstanceModel $instance): void
    {
        try {
            if (DB::connection('provisioner')->getDriverName() === 'sqlite') {
                return;
            }

            $database = CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database);
            $username = CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database_username);
            DB::connection('provisioner')->statement("DROP DATABASE IF EXISTS `{$database}`");
            DB::connection('provisioner')->statement("DROP USER IF EXISTS '{$username}'@'%'");
        } catch (\Throwable $cleanupError) {
            report($cleanupError);
        }
    }
}
