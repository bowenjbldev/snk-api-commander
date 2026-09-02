<?php

namespace Packages\Kilkana\Console\Commands;

use Illuminate\Console\Command;
use Packages\Kilkana\Services\SnkKilkanaTenantRunnerService;
use Packages\Kilkana\Services\SnkKilkanaTrashService;

/**
 * La cuenta atrás de la papelera: lo que lleva más de treinta días tirado se purga, en cada
 * instancia provisionada. Corre cada noche por `schedule:run`. Qué se purga lo decide
 * `SnkKilkanaTrashService::purgeExpired`, y dónde corre `SnkKilkanaTenantRunnerService`.
 */
class SnkKilkanaPurgeTrashCommand extends Command
{
    protected $signature = 'kilkana:purge-trash';

    protected $description = 'Purge Kilkana trash items older than the retention window, across all provisioned tenants';

    public function __construct(
        private readonly SnkKilkanaTenantRunnerService $tenants,
        private readonly SnkKilkanaTrashService $trash,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $run = $this->tenants->each(fn () => $this->trash->purgeExpired());

        foreach ($run['results'] as $database => $purged) {
            if ($purged) {
                $this->info("[{$database}] purged {$purged} item(s)");
            }
        }
        foreach ($run['errors'] as $database => $message) {
            $this->error("[{$database}] {$message}");
        }

        $total = array_sum($run['results']);
        $failures = count($run['errors']);
        $this->info("Done: {$total} item(s) purged across {$run['tenants']} tenant(s); {$failures} failed.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
