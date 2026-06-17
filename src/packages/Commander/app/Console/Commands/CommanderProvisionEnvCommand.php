<?php

namespace Packages\Commander\Console\Commands;

use Illuminate\Console\Command;
use Packages\Commander\Models\QuotaModel;

/**
 * Emits the plan quota limits of a (pending) deployment as CORE_QUOTA_* env lines on stdout, so the
 * host provisioner (scripts/subscription.sh) can bake them into the deploy's environment — the deploy
 * seeds its core_quota_metrics limits from config('core.quotas.defaults') = these env vars at migrate.
 * Prints nothing if the deployment has no limited quotas (all unlimited).
 */
class CommanderProvisionEnvCommand extends Command
{
    protected $signature = 'commander:provision-env {deployment : Deployment id/slug}';

    protected $description = 'Print CORE_QUOTA_* env lines from a deployment\'s mirrored plan quotas.';

    /** metric_key → CORE_QUOTA_* env name. */
    private const ENV_MAP = [
        'requests_day' => 'CORE_QUOTA_REQUESTS_DAY',
        'disk_db_mb' => 'CORE_QUOTA_DISK_DB_MB',
        'disk_storage_mb' => 'CORE_QUOTA_DISK_STORAGE_MB',
        'users_total' => 'CORE_QUOTA_USERS_TOTAL',
    ];

    public function handle(): int
    {
        $quotas = QuotaModel::query()
            ->where('deployment_id', (string) $this->argument('deployment'))
            ->whereNotNull('limit_value')
            ->get();

        foreach ($quotas as $quota) {
            $env = self::ENV_MAP[$quota->metric_key] ?? null;
            if ($env !== null) {
                $this->line("{$env}={$quota->limit_value}");
            }
        }

        return self::SUCCESS;
    }
}
