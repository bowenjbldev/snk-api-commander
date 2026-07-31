<?php

namespace Packages\Core\Support;

use Packages\Commander\Models\CommanderPackageModel;

/**
 * Instancia (cliente, package) que sirve el request actual. La fija
 * CoreResolvePackageFromHostMiddleware a partir del host y del slug del path. Sin request tenant
 * (CLI, host del operador/apex) queda null. Singleton: el valor vive durante el request.
 */
class CoreActivePackageSupport
{
    private ?CommanderPackageModel $package = null;

    public function set(?CommanderPackageModel $package): void
    {
        $this->package = $package;
    }

    public function forget(): void
    {
        $this->package = null;
    }

    public function current(): ?CommanderPackageModel
    {
        return $this->package;
    }

    public function id(): ?int
    {
        return $this->package?->getKey();
    }
}
