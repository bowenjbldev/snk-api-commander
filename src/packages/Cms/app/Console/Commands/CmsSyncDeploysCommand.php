<?php

namespace Packages\Cms\Console\Commands;

use Illuminate\Console\Command;
use Packages\Cms\Exceptions\CmsControlException;
use Packages\Cms\Models\DeploymentModel;
use Packages\Cms\Services\CmsDeploymentSyncService;

/**
 * Refreshes registered deploys (state + quotas + usage snapshots) from their /control endpoints.
 * Scheduled hourly by CmsServiceProvider; unreachable deploys are skipped, not fatal.
 */
class CmsSyncDeploysCommand extends Command
{
    protected $signature = 'cms:sync-deploys {--id= : Sync only this deployment id}';

    protected $description = 'Refresh registered deploys from their /control endpoints (skips unreachable ones).';

    public function handle(CmsDeploymentSyncService $sync): int
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
            } catch (CmsControlException $e) {
                $skipped++;
                $this->warn("skip {$deployment->id}: {$e->getMessage()}");
            }
        }

        $this->info("Synced {$ok}, skipped {$skipped} of {$deployments->count()}.");

        return self::SUCCESS;
    }
}
