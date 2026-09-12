<?php

namespace Packages\Commander\Console\Commands;

use Illuminate\Console\Command;
use Packages\Commander\Services\CommanderUsageRollupService;

class CommanderUsageRollupCommand extends Command
{
    protected $signature = 'commander:usage:rollup
        {--period=both : Periods to aggregate (hour|day|both)}
        {--prune : Delete raw rows older than core.metering.retention_days}';

    protected $description = 'Aggregate commander_request_logs into hourly/daily rollups (idempotent)';

    public function handle(CommanderUsageRollupService $service): int
    {
        $option = (string) $this->option('period');
        $periods = match ($option) {
            'hour' => ['hour'],
            'day' => ['day'],
            'both' => ['hour', 'day'],
            default => null,
        };

        if ($periods === null) {
            $this->error("Invalid period [{$option}]: use hour|day|both.");
            return self::FAILURE;
        }

        $retention = (int) config('core.metering.retention_days', 30);

        if (in_array('day', $periods, true) && $retention < 2) {
            $this->warn('retention_days < 2: the daily rollup is computed from the raw table and may be incomplete.');
        }

        foreach ($periods as $period) {
            $rows = $service->rollup($period);
            $this->info("Rollup [{$period}]: {$rows} rows aggregated.");
        }

        if ($this->option('prune')) {
            $deleted = $service->prune();
            $this->info("Prune: {$deleted} raw rows deleted (retention {$retention} days).");
        }

        return self::SUCCESS;
    }
}
