<?php

namespace Packages\Commander\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Core\Support\CorePackageSelectorSupport;

class CommanderSecureTenantsCommand extends Command
{
    protected $signature = 'commander:secure-tenants';

    protected $description = 'Create restricted database users for existing ready instances';

    public function handle(): int
    {
        $instances = CommanderInstanceModel::query()->ready()->whereNotNull('database')->get();

        foreach ($instances as $instance) {
            $user = CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database_username ?: 'tenant_'.$instance->id);
            $database = CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database);
            $password = $instance->database_password ?: Str::random(48);

            if (DB::connection('provisioner')->getDriverName() !== 'sqlite') {
                $connection = DB::connection('provisioner');
                $quotedPassword = $connection->getPdo()->quote($password);
                if ($quotedPassword === false) {
                    throw new \RuntimeException('Could not quote a tenant database password.');
                }
                $connection->unprepared("CREATE USER IF NOT EXISTS '{$user}'@'%' IDENTIFIED BY {$quotedPassword}");
                $connection->unprepared("ALTER USER '{$user}'@'%' IDENTIFIED BY {$quotedPassword}");
                $connection->statement("GRANT SELECT, INSERT, UPDATE, DELETE ON `{$database}`.* TO '{$user}'@'%'");
            }

            $instance->forceFill(['database_username' => $user, 'database_password' => $password])->save();
        }

        $this->info("Secured {$instances->count()} tenant(s).");

        return self::SUCCESS;
    }
}
