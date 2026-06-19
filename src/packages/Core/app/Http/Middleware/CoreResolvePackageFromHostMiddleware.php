<?php

namespace Packages\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Core\Support\CoreActivePackageSupport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modo centralizado: resuelve el package del subdominio ({sub}.{BASE_DOMAIN}) y lo fija
 * como activo del request (lo leen state, metering y cuotas). Subdominio sin fila en
 * packages (no provisionado) => 404. Ademas corta con 401 un token Sanctum emitido
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

        // El registro `packages` vive en la BD commander; se lee por query builder (su modelo
        // PackageModel es de Commander y Core no lo importa). La conexion 'commander' es fija,
        // asi que resuelve aunque luego cambiemos la conexion default a la del tenant.
        $package = DB::connection('commander')->table('commander_packages')->where('subdomain', $subdomain)->first();

        if ($package === null) {
            abort(404); // subdominio no provisionado
        }

        $this->active->set((int) $package->id);

        // Aislamiento FISICO: conmuta la conexion default a la BD del package. Bajo PHP-FPM cada
        // request es share-nothing, asi que el cambio vive solo en este request (no hay bleed).
        // Las tablas de la BD commander (packages, control-plane, commander_*) van pinneadas a 'commander'.
        // El token de un tenant ni existe en la BD de otro -> 401 automatico, sin check explicito.
        if (! empty($package->database)) {
            config(['database.connections.tenant.database' => $package->database]);
            DB::purge('tenant');
            DB::setDefaultConnection('tenant');
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
