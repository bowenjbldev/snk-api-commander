<?php

namespace Packages\Kilkana\Console\Commands;

use Illuminate\Console\Command;
use Packages\Kilkana\Services\SnkKilkanaImageService;
use Packages\Kilkana\Services\SnkKilkanaTenantRunnerService;

/** Barre las derivadas que ya no apunta ninguna huella vigente, periódico a periódico. */
class SnkKilkanaPurgeVariantsCommand extends Command
{
    protected $signature = 'kilkana:purge-variants';

    protected $description = 'Delete image derivatives whose fingerprint is no longer current, across all provisioned tenants';

    public function __construct(private readonly SnkKilkanaTenantRunnerService $tenants, private readonly SnkKilkanaImageService $images)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $run = $this->tenants->each(fn () => $this->images->purgeOrphans());
        foreach ($run['results'] as $database => $n) {
            if ($n) {
                $this->info("[{$database}] swept {$n} folder(s)");
            }
        }
        foreach ($run['errors'] as $database => $message) {
            $this->error("[{$database}] {$message}");
        }

        return count($run['errors']) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
