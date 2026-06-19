<?php

namespace Packages\Core\Support;

use Packages\Commander\Models\CommanderPackageModel;

/**
 * Resuelve el package cuyo subdominio sirvio el request actual. Lo fija por request
 * CoreResolvePackageFromHostMiddleware; fuera de un request HTTP (CLI, tests, deploy
 * de un solo package) cae a API_PACKAGE, de modo que el codigo que corre sin host
 * tambien resuelve un package. Singleton: el valor vive durante el request.
 */
class CoreActivePackageSupport
{
    private ?CommanderPackageModel $package = null;

    private bool $resolved = false;

    public function set(?CommanderPackageModel $package): void
    {
        $this->package = $package;
        $this->resolved = true;
    }

    public function forget(): void
    {
        $this->package = null;
        $this->resolved = false;
    }

    public function current(): ?CommanderPackageModel
    {
        if (! $this->resolved) {
            $this->package = $this->resolveFromEnvironment();
            $this->resolved = true;
        }

        return $this->package;
    }

    public function id(): ?int
    {
        return $this->current()?->getKey();
    }

    private function resolveFromEnvironment(): ?CommanderPackageModel
    {
        $name = CorePackageSelectorSupport::activePackageName();

        if ($name === null) {
            return null;
        }

        try {
            return CommanderPackageModel::query()->where('name', $name)->first();
        } catch (\Throwable) {
            return null; // tabla aun no migrada
        }
    }
}
