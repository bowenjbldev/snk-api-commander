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

    /**
     * Subdominio de un solo nivel a partir del host: {sub}.{BASE_DOMAIN} -> sub.
     * Devuelve null para el apex, hosts ajenos al base, o multinivel (a.b.base).
     */
    public static function subdomainFromHost(string $host): ?string
    {
        $base = (string) config('core.base_domain');

        if ($base === '' || $host === $base) {
            return null;
        }

        $suffix = '.' . $base;

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $sub = substr($host, 0, -strlen($suffix));

        return ($sub === '' || str_contains($sub, '.')) ? null : $sub;
    }
}
