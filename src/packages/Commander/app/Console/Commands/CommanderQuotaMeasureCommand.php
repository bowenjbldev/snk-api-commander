<?php

namespace Packages\Commander\Console\Commands;

use Illuminate\Console\Command;
use Packages\Commander\Services\CommanderQuotaMeasurerService;

class CommanderQuotaMeasureCommand extends Command
{
    protected $signature = 'commander:quota:measure
        {--dry-run : Mide y muestra sin persistir ni aplicar acciones}';

    protected $description = 'Measure instance quotas and apply their configured action';

    public function handle(CommanderQuotaMeasurerService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $results = $service->measureAll(persist: ! $dryRun, enforce: ! $dryRun);

        $this->table(
            ['Instance', 'Metric', 'Value', 'Limit', 'Action', 'State'],
            array_map(fn ($r) => [
                $r['quota']->instance_id,
                $r['quota']->metric->key,
                $r['value'] ?? 'n/a',
                $r['quota']->limit_value ?? 'unlimited',
                $r['quota']->action,
                $r['over'] ? 'OVER' : 'OK',
            ], $results)
        );

        if ($dryRun) {
            $this->warn('Dry-run: nada persistido ni aplicado.');
        }

        return self::SUCCESS;
    }
}
