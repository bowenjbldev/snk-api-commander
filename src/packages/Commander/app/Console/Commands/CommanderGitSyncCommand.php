<?php

namespace Packages\Commander\Console\Commands;

use Illuminate\Console\Command;
use Packages\Commander\Services\CommanderGitSyncService;

class CommanderGitSyncCommand extends Command
{
    protected $signature = 'commander:git:sync
        {--package= : Limita a un package (name StudlyCase o slug)}';

    protected $description = 'Sincroniza el historial git por package (commits + rollup diario) para los insights';

    public function handle(CommanderGitSyncService $service): int
    {
        $summary = $service->sync($this->option('package') ?: null);

        if ($summary === []) {
            $this->warn('No hay packages provisionados que sincronizar.');

            return self::SUCCESS;
        }

        foreach ($summary as $row) {
            $this->info("[{$row['package']}] {$row['commits']} commits nuevos, {$row['days']} dias actualizados.");
        }

        return self::SUCCESS;
    }
}
