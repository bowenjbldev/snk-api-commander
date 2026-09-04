<?php

namespace Packages\Kilkana\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Services\SnkKilkanaImageService;
use Packages\Kilkana\Services\SnkKilkanaTenantRunnerService;

/**
 * El máster de las fotos que entraron antes de la fábrica: se genera desde el original que
 * sigue en disco, con su huella, y desde ese momento tienen derivadas como las nuevas. Es de
 * una vez por instalación; volver a correrlo no toca lo que ya tiene máster.
 */
class SnkKilkanaBuildMastersCommand extends Command
{
    protected $signature = 'kilkana:build-masters';

    protected $description = 'Build the image master for media uploaded before derivatives existed, across all provisioned tenants';

    public function __construct(private readonly SnkKilkanaTenantRunnerService $tenants, private readonly SnkKilkanaImageService $images)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $run = $this->tenants->each(function (string $database) {
            $hechos = 0;
            SnkKilkanaMediaModel::withTrashed()
                ->whereNull('master_path')
                ->whereIn('mime', SnkKilkanaImageService::IMAGE_MIMES)
                ->whereNotNull('path')
                ->orderBy('id')
                ->each(function (SnkKilkanaMediaModel $media) use (&$hechos) {
                    $disk = Storage::disk($media->disk ?: 'public');
                    if (! $disk->exists($media->path)) {
                        return;
                    }
                    $master = $this->images->master($disk->path($media->path), dirname($media->path), pathinfo($media->path, PATHINFO_FILENAME));
                    if ($master === null) {
                        return;
                    }
                    $media->forceFill(['master_path' => $master['path'], 'master_width' => $master['width'], 'master_height' => $master['height']])->save();
                    $hechos++;
                });

            return $hechos;
        });

        foreach ($run['results'] as $database => $n) {
            $this->info("[{$database}] {$n} master(s) built");
        }
        foreach ($run['errors'] as $database => $message) {
            $this->error("[{$database}] {$message}");
        }

        return count($run['errors']) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
