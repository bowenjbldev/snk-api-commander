<?php

namespace Packages\Core\Support;

use Illuminate\Support\Facades\DB;

/**
 * Id del package cuyo subdominio sirvio el request actual. Lo fija por request
 * CoreResolvePackageFromHostMiddleware; fuera de un request HTTP (CLI, tests, deploy
 * de un solo package) cae a API_PACKAGE, resolviendo el id desde el registro `packages`.
 * NO acopla con el modelo del registro (vive en Commander): lo lee por query builder
 * sobre la conexion 'commander'. Singleton: el valor vive durante el request.
 */
class CoreActivePackageSupport
{
    private ?int $id = null;

    private bool $resolved = false;

    public function set(?int $packageId): void
    {
        $this->id = $packageId;
        $this->resolved = true;
    }

    public function forget(): void
    {
        $this->id = null;
        $this->resolved = false;
    }

    public function id(): ?int
    {
        if (! $this->resolved) {
            $this->id = $this->resolveFromEnvironment();
            $this->resolved = true;
        }

        return $this->id;
    }

    private function resolveFromEnvironment(): ?int
    {
        $name = CorePackageSelectorSupport::activePackageName();

        if ($name === null) {
            return null;
        }

        try {
            $id = DB::connection('commander')->table('commander_packages')->where('name', $name)->value('id');

            return $id !== null ? (int) $id : null;
        } catch (\Throwable) {
            return null; // tabla aun no migrada
        }
    }
}
