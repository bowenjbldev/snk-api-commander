<?php

namespace Packages\Commander\Console\Commands;

use Illuminate\Console\Command;
use Packages\Commander\Models\DeploymentModel;
use Packages\Commander\Models\QuotaModel;
use Packages\Commander\Models\UsageSnapshotModel;

/**
 * Removes a deploy from the Commander registry (called by subscription.sh on `remove`). Drops the
 * deployment row + its quota mirror; the client row is left in place.
 */
class CommanderDeregisterDeployCommand extends Command
{
    protected $signature = 'commander:deregister-deploy {deployment : Deployment id/slug to remove}';

    protected $description = 'Remove a deploy from the Commander registry.';

    public function handle(): int
    {
        $deployment = (string) $this->argument('deployment');
        $row = DeploymentModel::find($deployment);

        if ($row === null) {
            $this->warn("Deployment [{$deployment}] is not registered — nothing to do.");

            return self::SUCCESS;
        }

        QuotaModel::where('deployment_id', $deployment)->delete();
        UsageSnapshotModel::where('deployment_id', $deployment)->delete();
        $row->delete();

        $this->info("Deregistered deployment [{$deployment}].");

        return self::SUCCESS;
    }
}
