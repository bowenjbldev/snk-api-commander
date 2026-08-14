<?php

namespace Packages\Commander\Console\Commands;

use Illuminate\Console\Command;
use Packages\Commander\Services\CommanderGitSyncService;

class CommanderGitSyncCommand extends Command
{
    protected $signature = 'commander:git:sync
        {--application= : Limit to an application package name or slug}';

    protected $description = 'Synchronize git history by application';

    public function handle(CommanderGitSyncService $service): int
    {
        $summary = $service->sync($this->option('application') ?: null);

        if ($summary === []) {
            $this->warn('No available applications to synchronize.');

            return self::SUCCESS;
        }

        foreach ($summary as $row) {
            $this->info("[{$row['application']}] {$row['commits']} new commits, {$row['days']} updated days.");
        }

        return self::SUCCESS;
    }
}
