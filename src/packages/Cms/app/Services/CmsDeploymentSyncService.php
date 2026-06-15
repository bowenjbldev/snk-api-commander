<?php

namespace Packages\Cms\Services;

use Carbon\CarbonImmutable;
use Packages\Cms\Exceptions\CmsControlException;
use Packages\Cms\Models\DeploymentModel;
use Packages\Cms\Models\QuotaModel;
use Packages\Cms\Models\UsageSnapshotModel;

/**
 * Pulls a deploy's live state + quotas + usage into the local mirror. Shared by the on-demand sync
 * action (CmsDeploymentsController::sync) and the scheduled `cms:sync-deploys` command, and reused by
 * the operator state actions to mirror the deploy's echoed state.
 */
class CmsDeploymentSyncService
{
    public function __construct(private readonly CmsControlClient $control)
    {
    }

    /**
     * Refresh the deployment from its /control endpoints. Returns the fresh model.
     *
     * @throws CmsControlException when the deploy is unreachable or returns an error.
     */
    public function sync(DeploymentModel $deployment): DeploymentModel
    {
        $state = $this->control->getState($deployment);
        $usage = $this->control->getUsage($deployment, [
            'period' => 'hour',
            'from' => CarbonImmutable::now()->subDays(2)->toIso8601String(),
            'per_page' => 1000,
        ]);

        $this->applyState($deployment, $state['state'] ?? []);
        $this->applyQuotas($deployment, $state['quotas'] ?? []);
        $this->applySnapshots($deployment, $usage);

        $deployment->requests_24h = $this->sumRequests($usage, 24);
        $deployment->quota_health = $this->worstQuotaHealth($deployment);
        $deployment->last_seen = CarbonImmutable::now();
        $deployment->save();

        return $deployment->fresh();
    }

    /** Write the deploy's echoed state into the flat mirror columns; only stamp changed_at on a real flip. */
    public function applyState(DeploymentModel $deployment, array $state): void
    {
        if ($state === []) {
            return;
        }

        $newStatus = $state['effective_status'] ?? $state['status'] ?? $deployment->status;
        $flipped = $deployment->status !== $newStatus;

        $deployment->status = $newStatus;
        $deployment->reason = $state['reason'] ?? null;
        $deployment->message = $state['message'] ?? null;
        $deployment->grace_until = isset($state['grace_until']) ? CarbonImmutable::parse($state['grace_until']) : null;
        $deployment->changed_by = $state['changed_by'] ?? 'cms';

        if ($flipped || $deployment->changed_at === null) {
            $deployment->changed_at = CarbonImmutable::now();
        }

        $deployment->save();
    }

    /** Upsert the deploy's quotas[] into the local cms_quota_metrics mirror. */
    private function applyQuotas(DeploymentModel $deployment, array $quotas): void
    {
        foreach ($quotas as $q) {
            $metric = $q['metric'] ?? null;
            if (!is_string($metric) || $metric === '') {
                continue;
            }

            QuotaModel::updateOrCreate(['id' => $deployment->id . ':' . $metric], [
                'deployment_id' => $deployment->id,
                'metric_key' => $metric,
                'current_value' => (int) ($q['current'] ?? 0),
                'limit_value' => $q['limit'] ?? null,
                'on_exceed' => $q['on_exceed'] ?? 'none',
                'exceeded_at' => isset($q['exceeded_at']) ? CarbonImmutable::parse($q['exceeded_at']) : null,
                'unit' => str_ends_with($metric, '_mb') ? 'mb' : 'count',
            ]);
        }
    }

    /** Upsert the deploy's hourly usage into the local snapshot cache that powers the platform charts. */
    private function applySnapshots(DeploymentModel $deployment, array $usage): void
    {
        $byBucket = [];
        foreach (($usage['data'] ?? []) as $row) {
            $ps = $row['period_start'] ?? null;
            if (!is_string($ps) || $ps === '') {
                continue;
            }
            $byBucket[$ps] ??= ['requests' => 0, 'errors' => 0];
            $byBucket[$ps]['requests'] += (int) ($row['request_count'] ?? 0);
            $byBucket[$ps]['errors'] += (int) ($row['error_count'] ?? 0);
        }

        foreach ($byBucket as $ps => $agg) {
            $bucket = CarbonImmutable::parse($ps);
            UsageSnapshotModel::updateOrCreate(['id' => $deployment->id . ':hour:' . $bucket->timestamp], [
                'deployment_id' => $deployment->id,
                'period' => 'hour',
                'period_start' => $bucket,
                'request_count' => $agg['requests'],
                'error_count' => $agg['errors'],
            ]);
        }
    }

    private function sumRequests(array $usage, ?int $withinHours = null): int
    {
        $cutoff = $withinHours !== null ? CarbonImmutable::now()->subHours($withinHours) : null;
        $total = 0;
        foreach (($usage['data'] ?? []) as $row) {
            $ps = $row['period_start'] ?? null;
            if ($cutoff !== null && is_string($ps) && CarbonImmutable::parse($ps)->lessThan($cutoff)) {
                continue;
            }
            $total += (int) ($row['request_count'] ?? 0);
        }

        return $total;
    }

    /** Worst current/limit ratio across limited quotas (matches the SPA's quota_health). */
    private function worstQuotaHealth(DeploymentModel $deployment): float
    {
        $worst = 0.0;
        foreach ($deployment->quotas()->get() as $quota) {
            if ($quota->limit_value !== null && $quota->limit_value > 0) {
                $worst = max($worst, $quota->current_value / $quota->limit_value);
            }
        }

        return round($worst, 4);
    }
}
