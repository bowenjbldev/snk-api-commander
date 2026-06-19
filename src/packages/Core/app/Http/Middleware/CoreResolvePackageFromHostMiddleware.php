<?php

namespace Packages\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Core\Support\CoreActivePackageSupport;
use Packages\Core\Support\CorePackageSelectorSupport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modo centralizado: resuelve el package del subdominio ({sub}.{BASE_DOMAIN}) y lo fija
 * como activo del request (lo leen state, metering y cuotas). Subdominio sin fila en
 * commander_packages (no provisionado) => 404.
 *
 * Va el primero del grupo de cada package de negocio, antes que metering. Reusa el package
 * que ya resolvio CoreApplyPackageConfigMiddleware (global, pre-routing); si no esta, lo
 * resuelve aqui (fallback) para no depender de el.
 */
class CoreResolvePackageFromHostMiddleware
{
    public function __construct(private readonly CoreActivePackageSupport $active)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $package = $request->attributes->get('core_active_package');

        if (! $package instanceof CommanderPackageModel) {
            // El registro vive en la BD commander; CommanderPackageModel esta pinneado a esa
            // conexion, asi que resuelve aunque luego cambiemos la conexion default a la del tenant.
            $subdomain = CorePackageSelectorSupport::subdomainFromHost($request->getHost());

            $package = $subdomain !== null
                ? CommanderPackageModel::query()->where('subdomain', $subdomain)->first()
                : null;
        }

        if ($package === null) {
            abort(404); // subdominio no provisionado
        }

        $this->active->set($package);

        // Aislamiento FISICO: conmuta la conexion default a la BD del package. Bajo PHP-FPM cada
        // request es share-nothing, asi que el cambio vive solo en este request (no hay bleed).
        // El token de un tenant ni existe en la BD de otro -> 401 automatico, sin check explicito.
        if (! empty($package->database)) {
            config(['database.connections.tenant.database' => $package->database]);
            DB::purge('tenant');
            DB::setDefaultConnection('tenant');
        }

        return $next($request);
    }
}
