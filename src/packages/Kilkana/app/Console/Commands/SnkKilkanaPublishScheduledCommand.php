<?php

namespace Packages\Kilkana\Console\Commands;

use Illuminate\Console\Command;
use Packages\Kilkana\Services\SnkKilkanaPostService;
use Packages\Kilkana\Services\SnkKilkanaTenantRunnerService;

/**
 * Scheduler editorial: publica los posts `scheduled` cuya fecha vencio, en cada instancia
 * provisionada de Kilkana. Corre por cron via schedule:run (registrado en el provider).
 *
 * Aqui solo queda lo que es de consola: rotulo, salida y codigo de salida. Que se publica lo
 * decide `SnkKilkanaPostService::publishDue`, y donde corre `SnkKilkanaTenantRunnerService`.
 */
class SnkKilkanaPublishScheduledCommand extends Command
{
    protected $signature = 'kilkana:publish-scheduled';

    protected $description = 'Publish scheduled Kilkana posts whose time has come, across all provisioned tenants';

    public function __construct(
        private readonly SnkKilkanaTenantRunnerService $tenants,
        private readonly SnkKilkanaPostService $posts,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Las dos direcciones en el mismo tick: lo que toca salir y lo que toca retirar.
        $run = $this->tenants->each(fn () => ['published' => $this->posts->publishDue(), 'unpublished' => $this->posts->unpublishDue()]);

        foreach ($run['results'] as $database => $cuenta) {
            if ($cuenta['published']) {
                $this->info("[{$database}] published {$cuenta['published']} post(s)");
            }
            if ($cuenta['unpublished']) {
                $this->info("[{$database}] took down {$cuenta['unpublished']} post(s)");
            }
        }
        foreach ($run['errors'] as $database => $message) {
            $this->error("[{$database}] {$message}");
        }

        $total = array_sum(array_column($run['results'], 'published'));
        $failures = count($run['errors']);
        $this->info("Done: {$total} post(s) published across {$run['tenants']} tenant(s); {$failures} failed.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
