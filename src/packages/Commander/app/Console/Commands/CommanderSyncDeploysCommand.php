<?php

namespace Packages\Commander\Console\Commands;

use Illuminate\Console\Command;
use Packages\Commander\Exceptions\CommanderControlException;
use Packages\Commander\Models\DeploymentModel;
use Packages\Commander\Services\CommanderDeploymentSyncService;

/**
 * Refreshes registered deploys (state + quotas + usage snapshots) from their /control endpoints.
 * Scheduled hourly by CommanderServiceProvider; unreachable deploys are skipped, not fatal.
 */
class CommanderSyncDeploysCommand extends Command
{
    protected $signature = 'commander:sync-deploys {--id= : Sync only this deployment id}';

    protected $description = 'Refresh registered deploys from their /control endpoints (skips unreachable ones).';

    public function handle(CommanderDeploymentSyncService $sync): int
    {
        $query = DeploymentModel::query()
            ->whereNotNull('base_url')
            ->where('base_url', '!=', '');

        if ($id = $this->option('id')) {
            $query->where('id', $id);
        }

        $deployments = $query->get();
        $ok = 0;
        $skipped = 0;

        foreach ($deployments as $deployment) {
            try {
                $sync->sync($deployment);
                $ok++;
            } catch (CommanderControlException $e) {
                $skipped++;
                $this->warn("skip {$deployment->id}: {$e->getMessage()}");
            }
        }

        $this->info("Synced {$ok}, skipped {$skipped} of {$deployments->count()}.");

        return self::SUCCESS;
    }
}
