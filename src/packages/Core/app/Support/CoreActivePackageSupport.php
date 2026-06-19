<?php

namespace Packages\Core\Support;

use Packages\Commander\Models\CommanderPackageModel;

/**
 * Package cuyo subdominio sirvio el request actual. Lo fija por request
 * CoreResolvePackageFromHostMiddleware. Sin request con package (CLI, host del operador/apex)
 * queda null; el codigo que necesita un package concreto lo pasa explicito. Singleton: el
 * valor vive durante el request.
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
