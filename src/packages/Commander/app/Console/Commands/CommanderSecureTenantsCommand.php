<?php

namespace Packages\Commander\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packages\Commander\Models\CommanderPackageModel;

class CommanderSecureTenantsCommand extends Command
{
    protected $signature = 'commander:secure-tenants';

    protected $description = 'Create restricted database users for existing provisioned tenants';

    public function handle(): int
    {
        $packages = CommanderPackageModel::query()
            ->provisioned()
            ->whereNotNull('database')
            ->get();

        foreach ($packages as $package) {
            $user = $package->database_username ?: 'tenant_'.$package->id;
            $password = $package->database_password ?: Str::random(48);

            if (DB::connection('provisioner')->getDriverName() !== 'sqlite') {
                $connection = DB::connection('provisioner');
                $connection->statement("CREATE USER IF NOT EXISTS '{$user}'@'%' IDENTIFIED BY '{$password}'");
                $connection->statement("ALTER USER '{$user}'@'%' IDENTIFIED BY '{$password}'");
                $connection->statement(
                    "GRANT SELECT, INSERT, UPDATE, DELETE ON `{$package->database}`.* TO '{$user}'@'%'"
                );
            }

            $package->forceFill([
                'database_username' => $user,
                'database_password' => $password,
            ])->save();
        }

        $this->info("Secured {$packages->count()} tenant(s).");

        return self::SUCCESS;
    }
}
