<?php

namespace Packages\Core\Console\Commands;

use Illuminate\Console\Command;
use Packages\Core\Services\CoreUsageRollupService;

class CoreUsageRollupCommand extends Command
{
    protected $signature = 'core:usage:rollup
        {--period=both : Periodos a agregar (hour|day|both)}
        {--prune : Elimina filas crudas mas antiguas que core.metering.retention_days}';

    protected $description = 'Agrega request_logs en rollups por hora/dia (idempotente)';

    public function handle(CoreUsageRollupService $service): int
    {
        $option = (string) $this->option('period');
        $periods = match ($option) {
            'hour' => ['hour'],
            'day' => ['day'],
            'both' => ['hour', 'day'],
            default => null,
        };

        if ($periods === null) {
            $this->error("Periodo invalido [{$option}]: usa hour|day|both.");
            return self::FAILURE;
        }

        $retention = (int) config('core.metering.retention_days', 30);

        if (in_array('day', $periods, true) && $retention < 2) {
            $this->warn('retention_days < 2: el rollup diario se calcula desde la tabla cruda y puede quedar incompleto.');
        }

        foreach ($periods as $period) {
            $rows = $service->rollup($period);
            $this->info("Rollup [{$period}]: {$rows} filas agregadas.");
        }

        if ($this->option('prune')) {
            $deleted = $service->prune();
            $this->info("Poda: {$deleted} filas crudas eliminadas (retencion {$retention} dias).");
        }

        return self::SUCCESS;
    }
}
