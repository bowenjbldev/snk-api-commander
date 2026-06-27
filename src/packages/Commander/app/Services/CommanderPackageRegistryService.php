<?php

namespace Packages\Commander\Services;

/**
 * Registro de packages de negocio anidados por cliente: el codigo vive en disco
 * (packages/<Cliente>/<Paquete>, via git). En el modelo INSTANCIA, una fila de commander_packages
 * ES un par (cliente, paquete) provisionado: las crea el provisioning, NO un sync de catalogo.
 * Este servicio solo informa de los TIPOS de paquete desplegados en disco (para el flag on_disk
 * y "que se puede provisionar").
 */
class CommanderPackageRegistryService
{
    /**
     * Tipos de paquete (StudlyCase) presentes en disco bajo algun cliente: el 2º nivel de
     * packages/<Cliente>/<Paquete>. El framework (Core, Commander) es de 1er nivel -> excluido.
     */
    public function availableOnDisk(): array
    {
        $dirs = glob(base_path('packages/*/*'), GLOB_ONLYDIR) ?: [];

        return array_values(array_unique(array_map('basename', $dirs)));
    }
}
