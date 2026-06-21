<?php

namespace Packages\Commander\Services;

use Packages\Commander\Models\CommanderPackageModel;

/**
 * Registro de packages de negocio: el codigo vive en disco (packages/*, via git) y la tabla unica
 * commander_packages guarda una fila por package con sus metadatos del operador (label/description/
 * listing_status). El disco es la fuente: el sync crea filas (solo-catalogo) para packages nuevos
 * pero nunca borra (los metadatos + el historial persisten; el provisioning rellena lo operativo).
 */
class CommanderPackageRegistryService
{
    /** Directorios de package en disco, excluyendo Core y Commander (nombres StudlyCase). */
    public function availableOnDisk(): array
    {
        $dirs = glob(base_path('packages/*'), GLOB_ONLYDIR) ?: [];

        return array_values(array_filter(
            array_map('basename', $dirs),
            fn ($name) => ! in_array($name, ['Core', 'Commander'], true),
        ));
    }

    /** Idempotente: crea una fila (solo-catalogo) por cada package en disco aun no registrado. Devuelve cuantos creo. */
    public function syncFromDisk(): int
    {
        $created = 0;

        foreach ($this->availableOnDisk() as $name) {
            $row = CommanderPackageModel::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'listing_status' => 'active'],
            );

            if ($row->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }
}
