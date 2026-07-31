<?php

namespace Packages\Core\Support;

use Illuminate\Support\Str;

final class CorePackageSelectorSupport
{
    private const RESERVED_SUBDOMAINS = ['api', 'commander', 'www'];

    /**
     * StudlyCase package name -> slug snake con guiones (MiPaquete -> mi-paquete). Sirve para el
     * slug del package en el path Y para el subdominio del cliente. Debe coincidir con el provisioning.
     */
    public static function packageToSubdomain(string $package): string
    {
        return str_replace('_', '-', Str::snake($package));
    }

    /**
     * Nombre de la BD aislada: snake(client.slug)_snake(package). Los guiones del slug
     * pasan a guion_bajo (MySQL no admite guiones en nombres de BD). Ej.: (acme, Shop) -> acme_shop.
     */
    public static function databaseName(string $clientSlug, string $package): string
    {
        return self::dbSlug($clientSlug).'_'.self::dbSlug($package);
    }

    public static function validatedDatabaseIdentifier(?string $value): string
    {
        if (! is_string($value) || preg_match('/\A[a-z0-9_]{1,64}\z/', $value) !== 1) {
            throw new \RuntimeException('Unsafe tenant database identifier.');
        }

        return $value;
    }

    public static function isReservedSubdomain(string $value): bool
    {
        return in_array(strtolower($value), self::RESERVED_SUBDOMAINS, true);
    }

    public static function isValidClientSubdomain(string $value): bool
    {
        return preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $value) === 1
            && ! self::isReservedSubdomain($value);
    }

    private static function dbSlug(string $value): string
    {
        return str_replace('-', '_', Str::snake($value));
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

        $suffix = '.'.$base;

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $sub = substr($host, 0, -strlen($suffix));

        return ($sub === '' || str_contains($sub, '.') || ! self::isValidClientSubdomain($sub)) ? null : $sub;
    }
}
