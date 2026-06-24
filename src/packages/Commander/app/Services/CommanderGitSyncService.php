<?php

namespace Packages\Commander\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Packages\Commander\Contracts\CommanderPackageGitSource;
use Packages\Commander\Models\CommanderPackageActivityDailyModel;
use Packages\Commander\Models\CommanderPackageCommitModel;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Commander\Models\CommanderPackageRepoModel;
use Packages\Commander\Support\CommanderGitCommitData;

/**
 * Sincroniza el historial git por package para los insights: por cada package provisionado pide los
 * commits NUEVOS a su CommanderPackageGitSource, los upserta en commander_package_commits (raw) y
 * recalcula los buckets diarios afectados en commander_package_activity_daily (heatmap + code
 * frequency). Idempotente: el cursor (last_synced_sha) acota cada pasada; los upserts no duplican.
 * Mismo patrón raw->rollup que CommanderUsageRollupService.
 */
class CommanderGitSyncService
{
    public function __construct(private readonly CommanderPackageGitSource $source)
    {
    }

    /**
     * @return array<int, array{package: string, commits: int, days: int}>
     */
    public function sync(?string $only = null): array
    {
        $query = CommanderPackageModel::query()->provisioned()->with('client');

        if ($only !== null && $only !== '') {
            $query->where(fn ($q) => $q->where('name', $only)->orWhere('slug', $only));
        }

        $summary = [];

        foreach ($query->get() as $package) {
            $summary[] = $this->syncPackage($package);
        }

        return $summary;
    }

    /**
     * @return array{package: string, commits: int, days: int}
     */
    private function syncPackage(CommanderPackageModel $package): array
    {
        $repo = $this->ensureRepo($package);
        $label = "{$package->client?->slug}/{$package->slug}";

        /** @var array<int, CommanderGitCommitData> $new */
        $new = [];
        foreach ($this->source->fetchCommits($repo, $repo->last_synced_sha) as $commit) {
            $new[] = $commit;
        }

        $now = CarbonImmutable::now();

        if ($new === []) {
            $repo->forceFill(['last_synced_at' => $now])->save();

            return ['package' => $label, 'commits' => 0, 'days' => 0];
        }

        $this->upsertCommits($package->id, $new, $now);
        $days = $this->rollupAffectedDays($package->id, $new, $now);

        // Cursor = el commit más reciente por fecha (independiente del orden de salida).
        $newest = collect($new)->sortByDesc(fn (CommanderGitCommitData $c) => $c->committedAt)->first();
        $repo->forceFill([
            'last_synced_sha' => $newest->sha,
            'last_synced_at' => $now,
        ])->save();

        return ['package' => $label, 'commits' => count($new), 'days' => $days];
    }

    /**
     * @param array<int, CommanderGitCommitData> $commits
     */
    private function upsertCommits(int $packageId, array $commits, CarbonImmutable $now): void
    {
        $nowStr = $now->format('Y-m-d H:i:s');
        $rows = [];

        foreach ($commits as $c) {
            $rows[] = [
                'package_id' => $packageId,
                'sha' => $c->sha,
                'committed_at' => $c->committedAt->format('Y-m-d H:i:s'),
                'author_name' => $c->authorName,
                'author_email' => $c->authorEmail,
                'message' => $c->message,
                'additions' => $c->additions,
                'deletions' => $c->deletions,
                'files_changed' => $c->filesChanged,
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ];
        }

        CommanderPackageCommitModel::query()->upsert(
            $rows,
            ['package_id', 'sha'],
            ['committed_at', 'author_name', 'author_email', 'message', 'additions', 'deletions', 'files_changed', 'updated_at'],
        );
    }

    /**
     * Recalcula (desde la tabla cruda) los buckets diarios de los días tocados por los commits nuevos.
     *
     * @param array<int, CommanderGitCommitData> $commits
     * @return int días actualizados
     */
    private function rollupAffectedDays(int $packageId, array $commits, CarbonImmutable $now): int
    {
        $days = [];
        foreach ($commits as $c) {
            $days[$c->committedAt->toDateString()] = true;
        }

        $nowStr = $now->format('Y-m-d H:i:s');
        $rows = [];

        foreach (array_keys($days) as $day) {
            $bucket = CarbonImmutable::parse($day);

            $stats = CommanderPackageCommitModel::query()
                ->where('package_id', $packageId)
                ->whereBetween('committed_at', [$bucket->startOfDay(), $bucket->endOfDay()])
                ->get(['additions', 'deletions', 'files_changed']);

            $rows[] = [
                'package_id' => $packageId,
                'day' => $day,
                'commit_count' => $stats->count(),
                'additions' => (int) $stats->sum('additions'),
                'deletions' => (int) $stats->sum('deletions'),
                'files_changed' => (int) $stats->sum('files_changed'),
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ];
        }

        CommanderPackageActivityDailyModel::query()->upsert(
            $rows,
            ['package_id', 'day'],
            ['commit_count', 'additions', 'deletions', 'files_changed', 'updated_at'],
        );

        return count($rows);
    }

    /**
     * Fila repo 1:1 del package; la crea al vuelo derivando el path del monorepo desde el cliente +
     * nombre del package (zero-config). path_prefix = src/packages/<ClientStudly>/<PackageName>.
     */
    private function ensureRepo(CommanderPackageModel $package): CommanderPackageRepoModel
    {
        return CommanderPackageRepoModel::firstOrCreate(
            ['package_id' => $package->id],
            [
                'provider' => 'bitbucket',
                'path_prefix' => 'src/packages/'.Str::studly((string) $package->client?->slug).'/'.$package->name,
                'default_branch' => 'main',
            ],
        );
    }
}
