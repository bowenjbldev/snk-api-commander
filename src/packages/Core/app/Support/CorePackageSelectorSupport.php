<?php

namespace Packages\Core\Support;

use Illuminate\Support\Str;

final class CorePackageSelectorSupport
{
    /**
     * StudlyCase package name -> subdominio snake con guiones (MiPaquete -> mi-paquete).
     * Debe coincidir con el provisioning del Commander.
     */
    public static function packageToSubdomain(string $package): string
    {
        return str_replace('_', '-', Str::snake($package));
    }
}
