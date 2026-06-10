<?php

namespace Packages\Core\Console\Commands;

use Illuminate\Console\Command;
use Packages\Core\Services\CoreQuotaMeasurerService;

class CoreQuotaMeasureCommand extends Command
{
    protected $signature = 'core:quota:measure
        {--dry-run : Mide y muestra sin persistir ni aplicar acciones}';

    protected $description = 'Mide las metricas de cuota y aplica on_exceed (warn/suspend)';

    public function handle(CoreQuotaMeasurerService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $results = $service->measureAll(persist: !$dryRun, enforce: !$dryRun);

        $this->table(
            ['Metrica', 'Valor', 'Limite', 'on_exceed', 'Estado'],
            array_map(fn ($r) => [
                $r['metric']->metric_key,
                $r['value'] ?? 'n/a',
                $r['metric']->limit_value ?? 'sin limite',
                $r['metric']->on_exceed,
                $r['over'] ? 'OVER' : 'OK',
            ], $results)
        );

        if ($dryRun) {
            $this->warn('Dry-run: nada persistido ni aplicado.');
        }

        return self::SUCCESS;
    }
}
