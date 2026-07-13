<?php

namespace Packages\Commander\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Commander\Models\CommanderSubscriptionModel;
use Packages\Commander\Models\CommanderUsageRollupModel;

class CommanderDeprovisionPackageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly int $packageId)
    {
        $this->onQueue('provisioning');
    }

    public function handle(): void
    {
        $package = CommanderPackageModel::findOrFail($this->packageId);

        if (DB::connection('provisioner')->getDriverName() !== 'sqlite') {
            DB::connection('provisioner')->statement("DROP DATABASE IF EXISTS `{$package->database}`");
            DB::connection('provisioner')->statement("DROP USER IF EXISTS '{$package->database_username}'@'%'");
        }

        DB::connection('commander')->transaction(function () use ($package): void {
            CommanderRequestLogModel::query()->where('package_id', $package->id)->delete();
            CommanderQuotaModel::query()->where('package_id', $package->id)->delete();
            CommanderUsageRollupModel::query()->where('package_id', $package->id)->delete();
            CommanderSubscriptionModel::query()
                ->where('package_id', $package->id)
                ->whereNull('ended_at')
                ->update(['ended_at' => now()]);

            $package->forceFill([
                'slug' => null,
                'database' => null,
                'database_username' => null,
                'database_password' => null,
                'client_id' => null,
                'subscription_id' => null,
                'status' => 'active',
                'reason' => null,
                'message' => null,
                'grace_until' => null,
                'changed_at' => now(),
                'requests_24h' => 0,
                'quota_health' => 0,
                'last_seen' => null,
                'provisioned_at' => null,
            ])->save();
        });
    }
}
