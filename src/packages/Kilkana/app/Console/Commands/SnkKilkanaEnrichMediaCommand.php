<?php

namespace Packages\Kilkana\Console\Commands;

use Illuminate\Console\Command;
use Packages\Kilkana\Services\SnkKilkanaMediaService;
use Packages\Kilkana\Services\SnkKilkanaTenantRunnerService;

/**
 * Rellena los huecos de metadatos de cada portada con lo que su artículo ya sabe, en cada
 * instancia provisionada.
 *
 * Aquí solo queda lo que es de consola. Qué se rellena lo decide
 * `SnkKilkanaMediaService::enrichFromPosts`, y dónde corre `SnkKilkanaTenantRunnerService`.
 */
class SnkKilkanaEnrichMediaCommand extends Command
{
    protected $signature = 'kilkana:enrich-media';

    protected $description = 'Fill media metadata from the editorial posts that use it';

    public function __construct(
        private readonly SnkKilkanaTenantRunnerService $tenants,
        private readonly SnkKilkanaMediaService $media,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $run = $this->tenants->each(fn () => $this->media->enrichFromPosts());

        foreach ($run['errors'] as $database => $message) {
            $this->error("[{$database}] {$message}");
        }

        $total = array_sum($run['results']);
        $failures = count($run['errors']);
        $this->info("Done: {$total} media item(s) enriched across {$run['tenants']} tenant(s); {$failures} failed.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
