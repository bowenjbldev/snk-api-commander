<?php

namespace Packages\Commander\Services;

use Packages\Commander\Models\CommanderRegisteredPackageModel;

/**
 * Registro de packages de negocio: el codigo vive en disco (packages/*, via git) y esta
 * tabla guarda los metadatos del operador (label/description/status). El disco es la fuente:
 * el sync crea filas para packages nuevos pero nunca borra (los metadatos persisten).
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

    /** Idempotente: crea una fila por cada package en disco aun no registrado. Devuelve cuantos creo. */
    public function syncFromDisk(): int
    {
        $created = 0;

        foreach ($this->availableOnDisk() as $name) {
            $row = CommanderRegisteredPackageModel::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'status' => 'active'],
            );

            if ($row->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }
}
