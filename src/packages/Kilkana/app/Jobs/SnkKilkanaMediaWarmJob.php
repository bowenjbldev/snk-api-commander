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
 * Calienta lo que seguro se pide nada más subir: las dos miniaturas del panel —la de la rejilla
 * de la mediateca y la de su tabla—, la imagen social y la tarjeta en los dos anchos de la
 * portada. El resto de derivadas nace bajo demanda. Sin modelos en el constructor: el worker
 * no está dentro del inquilino, y entra con la base que se le dice.
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
            // Las miniaturas del panel van también en avif, que es lo que el navegador del
            // panel pide primero: a 160 y 320 codificarlo cuesta poco y es lo que hace que la
            // rejilla salga entera a la primera visita en vez de generar cuarenta y ocho a la vez.
            // La rejilla pide `hero` a 160 en una pantalla normal y a 320 en una densa; la tabla,
            // `thumb` a 160.
            foreach ([['hero', 160], ['hero', 320], ['thumb', 160]] as [$ratio, $width]) {
                foreach (SnkKilkanaImageService::formats() as $format) {
                    $images->variant($media, $ratio, $width, $format);
                }
            }
            foreach ([['og', 1280], ['card', 640], ['card', 960]] as [$ratio, $width]) {
                // Solo webp y jpeg: a estos anchos el avif es caro de codificar y nace bajo demanda.
                foreach (['webp', 'jpeg'] as $format) {
                    $images->variant($media, $ratio, $width, $format);
                }
            }
        });
    }
}
