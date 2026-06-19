<?php

namespace Packages\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Packages\Core\Models\CorePackageModel;
use Packages\Core\Support\CoreActivePackageSupport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modo centralizado: resuelve el package del subdominio ({sub}.{BASE_DOMAIN}) y lo fija
 * como activo del request (lo leen state, metering y cuotas). Subdominio sin fila en
 * core_packages (no provisionado) => 404. Ademas corta con 401 un token Sanctum emitido
 * para OTRO package: aislamiento de identidad entre subdominios.
 *
 * Va el primero del grupo de cada package de negocio, antes que metering, para que el
 * registro de uso ya tenga el package resuelto.
 */
class CoreResolvePackageFromHostMiddleware
{
    public function __construct(private readonly CoreActivePackageSupport $active)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $subdomain = $this->subdomainFromHost($request->getHost());

        if ($subdomain === null) {
            abort(404);
        }

        $package = CorePackageModel::query()->where('subdomain', $subdomain)->first();

        if ($package === null) {
            abort(404); // subdominio no provisionado
        }

        $this->active->set($package);

        // Aislamiento de identidad: un token de otro package no vale en este subdominio.
        $token = $request->bearerToken();

        if ($token !== null) {
            $owner = PersonalAccessToken::findToken($token)?->tokenable;

            if ($owner !== null
                && $owner->package_id !== null
                && (int) $owner->package_id !== (int) $package->getKey()) {
                abort(401);
            }
        }

        return $next($request);
    }

    private function subdomainFromHost(string $host): ?string
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

        // Wildcard de un solo nivel: a.base si, a.b.base no.
        return ($sub === '' || str_contains($sub, '.')) ? null : $sub;
    }
}
