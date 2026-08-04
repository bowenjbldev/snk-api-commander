<?php

namespace Packages\Kilkana\Services;

use Illuminate\Support\Facades\DB;
use Packages\Kilkana\Enums\SnkKilkanaThemeSourceEnum;
use Packages\Kilkana\Models\SnkKilkanaThemeVariantModel;

/**
 * Vestir un tema del catálogo: copiarlo, no apuntarlo.
 *
 * Esta copia es lo único que hace real el congelado. Si los ajustes apuntaran a la fila del
 * catálogo, la siguiente sincronización reescribiría esa fila y el periódico se despertaría con
 * otra portada — sin que nadie lo hubiera pedido y sin rastro de qué cambió.
 *
 * Copiando, publicar la versión 2 de un tema deja intacto a quien lleva puesta la 1, y
 * `origin_version` es lo que permite avisarle de que hay algo nuevo y que decida él.
 */
class SnkKilkanaThemeAdoptionService
{
    /**
     * Devuelve la copia propia del periódico para esa variante.
     *
     * Es idempotente por origen: adoptar dos veces el mismo tema no crea dos copias, devuelve
     * la que ya había con sus retoques. Adoptar una fila que ya es propia la devuelve tal cual,
     * que es lo que permite llamar a esto sin mirar antes de qué tipo es.
     */
    public function adopt(SnkKilkanaThemeVariantModel $variante): SnkKilkanaThemeVariantModel
    {
        if ($variante->source === SnkKilkanaThemeSourceEnum::Tenant) {
            return $variante;
        }

        return DB::transaction(function () use ($variante) {
            $existente = SnkKilkanaThemeVariantModel::query()
                ->owned()
                ->where('origin_variant_id', $variante->id)
                ->first();

            if ($existente) {
                return $existente;
            }

            return SnkKilkanaThemeVariantModel::create([
                'theme_id' => $variante->theme_id,
                'source' => SnkKilkanaThemeSourceEnum::Tenant->value,
                'origin_variant_id' => $variante->id,
                // La versión del TEMA, que es lo que se compara para avisar de una actualización.
                'origin_version' => $variante->theme?->version,
                // El slug del catálogo ya está cogido, así que la copia deriva el suyo del
                // nombre; el desempate del modelo se encarga si dos temas se llaman igual.
                'name' => $variante->name,
                'spec' => $variante->spec,
                'position' => $variante->position,
                'thumbnail_path' => $variante->thumbnail_path,
            ]);
        });
    }

    /**
     * ¿Hay una versión más nueva del tema que esta copia?
     *
     * Se compara contra el tema y no contra la variante porque la versión es del tema entero:
     * publicar una variante nueva dentro de un tema también es una versión nueva del tema.
     */
    public function updateAvailable(SnkKilkanaThemeVariantModel $variante): bool
    {
        $publicada = $variante->origin?->theme?->version;

        return $publicada !== null
            && $variante->origin_version !== null
            && $publicada !== $variante->origin_version;
    }
}
