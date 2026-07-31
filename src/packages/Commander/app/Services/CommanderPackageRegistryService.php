<?php

namespace Packages\Commander\Services;

use Packages\Commander\Models\CommanderPackageModel;
use Packages\Core\Support\CorePackageDiscoverySupport;

/** Catálogo de productos desplegados e implementaciones compartidas/específicas. */
class CommanderPackageRegistryService
{
    /** @return string[] tipos StudlyCase; excluye Core, Commander y paraguas vacíos */
    public function availableOnDisk(): array
    {
        return $this->discovery()->availableBusinessPackages();
    }

    public function hasImplementation(string $client, string $package): bool
    {
        return $this->discovery()->hasImplementation($client, $package);
    }

    /** Mantiene una fila de catálogo para que un producto compartido pueda tener planes antes del primer alta. */
    public function syncCatalog(): void
    {
        foreach ($this->availableOnDisk() as $name) {
            CommanderPackageModel::firstOrCreate(
                ['name' => $name, 'client_id' => null, 'provisioned_at' => null],
                ['label' => $name, 'listing_status' => 'active', 'status' => 'active'],
            );
        }
    }

    private function discovery(): CorePackageDiscoverySupport
    {
        return CorePackageDiscoverySupport::fromEnvironment(base_path('packages'));
    }
}
