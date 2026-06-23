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
 * Centralizado, cliente-primero: el SUBDOMINIO es el CLIENTE ({client}.{BASE_DOMAIN}) y el SLUG
 * del package va en el path (/api/v1/{slug}). El resolutor recibe el slug como argumento de
 * middleware (lo conoce el registro de rutas) y el cliente lo deriva del host; juntos resuelven la
 * fila viva de commander_packages (subdomain del cliente + slug) y la fijan como package activo
 * (lo leen state, metering y cuotas). Sin fila provisionada => 404.
 *
 * También aplica los overrides de config GLOBAL del package (namespace {cliente}.{slug}, cargado
 * por el discovery): antes era un middleware global pre-routing, pero con subdominio = cliente el
 * package solo se conoce con el slug del path, así que el override vive aquí. API stateless.
 */
class CoreResolvePackageFromHostMiddleware
{
    public function __construct(private readonly CoreActivePackageSupport $active)
    {
    }

    public function handle(Request $request, Closure $next, string $slug): Response
    {
        // El registro vive en la BD commander; CommanderPackageModel está pinneado a esa conexión,
        // así que resuelve aunque luego cambiemos la conexión default a la del tenant.
        $clientSubdomain = CorePackageSelectorSupport::subdomainFromHost($request->getHost());

        $package = $clientSubdomain !== null
            ? CommanderPackageModel::query()
                ->whereNotNull('provisioned_at')
                ->whereHas('client', fn ($q) => $q->where('slug', $clientSubdomain))
                ->where('slug', $slug)
                ->first()
            : null;

        if ($package === null) {
            abort(404); // (cliente, slug) no provisionado
        }

        $this->active->set($package);

        // Aislamiento FÍSICO: conmuta la conexión default a la BD del package ({client}_{slug}).
        // Bajo PHP-FPM cada request es share-nothing -> el cambio vive solo en este request.
        // El token de un tenant ni existe en la BD de otro -> 401 automático, sin check explícito.
        if (! empty($package->database)) {
            config(['database.connections.tenant.database' => $package->database]);
            DB::purge('tenant');
            DB::setDefaultConnection('tenant');
        }

        $this->applyConfigOverrides("{$clientSubdomain}.{$slug}");

        return $next($request);
    }

    /** Fusiona los config del package que coinciden con una clave del skeleton sobre la global. */
    private function applyConfigOverrides(string $namespace): void
    {
        foreach ($this->globalConfigKeys() as $key) {
            $override = config("{$namespace}.{$key}");

            if (is_array($override) && $override !== []) {
                config([$key => array_replace_recursive((array) config($key, []), $override)]);
            }
        }
    }

    /** Claves del skeleton de Laravel (config/*.php): las únicas que un package puede overridear. */
    private function globalConfigKeys(): array
    {
        return array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            glob(config_path('*.php')) ?: [],
        );
    }
}
