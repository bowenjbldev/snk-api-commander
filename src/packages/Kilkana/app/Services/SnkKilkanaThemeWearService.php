<?php

namespace Packages\Kilkana\Services;

use Illuminate\Support\Facades\DB;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Models\SnkKilkanaThemeModel;

/**
 * Vestir un tema del catálogo: copiarlo, no apuntarlo.
 *
 * Esta copia es lo único que hace real el congelado. Si los ajustes apuntaran al `spec` de la
 * fila del catálogo, la siguiente sincronización reescribiría esa fila y el periódico se
 * despertaría con otra portada — sin que nadie lo hubiera pedido y sin rastro de qué cambió.
 *
 * Copiando, publicar la versión 2 de un tema deja intacto a quien lleva puesta la 1, y
 * `theme_version` es lo que permite avisarle de que hay algo nuevo y que decida él.
 */
class SnkKilkanaThemeWearService
{
    /**
     * Deja al periódico vestido con este tema y devuelve sus ajustes.
     *
     * **Los retoques se vacían.** Eran del tema anterior: arrastrarlos pintaría el acento de uno
     * sobre otro y nadie sabría de dónde salió. Volver a vestir el mismo tema es la vía para
     * recuperar el spec de fábrica, y por eso no es idempotente en los retoques.
     */
    public function wear(SnkKilkanaThemeModel $theme): SnkKilkanaSettingModel
    {
        return DB::transaction(function () use ($theme) {
            $settings = SnkKilkanaSettingModel::query()->firstOrCreate(['id' => 1]);

            $settings->forceFill([
                'theme_id' => $theme->id,
                'theme_spec' => $theme->spec,
                'theme_version' => $theme->version,
                'theme_overrides' => null,
            ])->save();

            return $settings;
        });
    }

    /**
     * ¿Hay una versión más nueva en el catálogo que la que lleva puesta el periódico?
     *
     * Compara versiones y no los dos JSON: la versión es lo que sube el fichero maestro al
     * publicar, así que es la única señal que no depende de cómo se serialice un spec.
     */
    public function updateAvailable(SnkKilkanaSettingModel $settings): bool
    {
        $published = $settings->theme?->version;

        return $published !== null
            && $settings->theme_version !== null
            && $published !== $settings->theme_version;
    }
}
