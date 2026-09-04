<?php

namespace Packages\Kilkana\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Services\SnkKilkanaImageService;
use Packages\Kilkana\Services\SnkKilkanaTenantRunnerService;

/**
 * Calienta lo que seguro se pide nada más publicar: la imagen social y la tarjeta en los dos
 * anchos de la portada. El resto de derivadas nace bajo demanda. Sin modelos en el constructor:
 * el worker no está dentro del inquilino, y entra con la base que se le dice.
 */
class SnkKilkanaMediaWarmJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(public readonly string $database, public readonly int $mediaId) {}

    public function handle(SnkKilkanaTenantRunnerService $tenants, SnkKilkanaImageService $images): void
    {
        $tenants->on($this->database, function () use ($images) {
            $media = SnkKilkanaMediaModel::withTrashed()->find($this->mediaId);
            if ($media === null) {
                return;
            }
            foreach ([['og', 1280], ['card', 640], ['card', 960]] as [$ratio, $width]) {
                // Solo webp y jpeg: el avif es caro de codificar y nace bajo demanda.
                foreach (['webp', 'jpeg'] as $format) {
                    $images->variant($media, $ratio, $width, $format);
                }
            }
        });
    }
}
