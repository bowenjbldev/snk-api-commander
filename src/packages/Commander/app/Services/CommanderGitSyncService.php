<?php

namespace Packages\Commander\Services;

use Carbon\CarbonImmutable;
use Packages\Commander\Contracts\CommanderPackageGitSource;
use Packages\Commander\Models\CommanderApplicationActivityDailyModel;
use Packages\Commander\Models\CommanderApplicationCommitModel;
use Packages\Commander\Models\CommanderApplicationModel;
use Packages\Commander\Models\CommanderApplicationRepoModel;
use Packages\Commander\Support\CommanderGitCommitData;

class CommanderGitSyncService
{
    public function __construct(private readonly CommanderPackageGitSource $source) {}

    /** @return array<int, array{application: string, commits: int, days: int}> */
    public function sync(?string $only = null): array
    {
        $query = CommanderApplicationModel::query()->where('code_status', 'available');
        if ($only !== null && $only !== '') {
            $query->where(fn ($q) => $q->where('package_name', $only)->orWhere('slug', $only));
        }

        return $query->get()->map(fn ($application) => $this->syncApplication($application))->all();
    }

    /** @return array{application: string, commits: int, days: int} */
    private function syncApplication(CommanderApplicationModel $application): array
    {
        $repo = $this->ensureRepo($application);
        $commits = [];
        foreach ($this->source->fetchCommits($repo, $repo->last_synced_sha) as $commit) {
            $commits[] = $commit;
        }
        $now = CarbonImmutable::now();

        if ($commits === []) {
            $repo->forceFill(['last_synced_at' => $now])->save();

            return ['application' => $application->slug, 'commits' => 0, 'days' => 0];
        }

        $this->upsertCommits($application->id, $commits, $now);
        $days = $this->rollupAffectedDays($application->id, $commits, $now);
        $newest = collect($commits)->sortByDesc(fn (CommanderGitCommitData $commit) => $commit->committedAt)->first();
        $repo->forceFill(['last_synced_sha' => $newest->sha, 'last_synced_at' => $now])->save();

        return ['application' => $application->slug, 'commits' => count($commits), 'days' => $days];
    }

    /** @param array<int, CommanderGitCommitData> $commits */
    private function upsertCommits(int $applicationId, array $commits, CarbonImmutable $now): void
    {
        $rows = array_map(fn (CommanderGitCommitData $commit) => [
            'application_id' => $applicationId,
            'sha' => $commit->sha,
            'committed_at' => $commit->committedAt->format('Y-m-d H:i:s'),
            'author_name' => $commit->authorName,
            'author_email' => $commit->authorEmail,
            'message' => $commit->message,
            'additions' => $commit->additions,
            'deletions' => $commit->deletions,
            'files_changed' => $commit->filesChanged,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ], $commits);

        CommanderApplicationCommitModel::query()->upsert(
            $rows,
            ['application_id', 'sha'],
            ['committed_at', 'author_name', 'author_email', 'message', 'additions', 'deletions', 'files_changed', 'updated_at'],
        );
    }

    /** @param array<int, CommanderGitCommitData> $commits */
    private function rollupAffectedDays(int $applicationId, array $commits, CarbonImmutable $now): int
    {
        $days = collect($commits)->map(fn (CommanderGitCommitData $commit) => $commit->committedAt->toDateString())->unique();
        $rows = $days->map(function (string $day) use ($applicationId, $now): array {
            $bucket = CarbonImmutable::parse($day);
            $stats = CommanderApplicationCommitModel::query()
                ->where('application_id', $applicationId)
                ->whereBetween('committed_at', [$bucket->startOfDay(), $bucket->endOfDay()])
                ->get(['additions', 'deletions', 'files_changed']);

            return [
                'application_id' => $applicationId,
                'day' => $day,
                'commit_count' => $stats->count(),
                'additions' => (int) $stats->sum('additions'),
                'deletions' => (int) $stats->sum('deletions'),
                'files_changed' => (int) $stats->sum('files_changed'),
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ];
        })->all();

        CommanderApplicationActivityDailyModel::query()->upsert(
            $rows,
            ['application_id', 'day'],
            ['commit_count', 'additions', 'deletions', 'files_changed', 'updated_at'],
        );

        return count($rows);
    }

    private function ensureRepo(CommanderApplicationModel $application): CommanderApplicationRepoModel
    {
        return CommanderApplicationRepoModel::firstOrCreate(
            ['application_id' => $application->id],
            ['path_prefix' => 'src/packages/'.$application->package_name, 'default_branch' => 'main'],
        );
    }
}
