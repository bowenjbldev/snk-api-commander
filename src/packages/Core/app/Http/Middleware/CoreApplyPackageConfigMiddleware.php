<?php

namespace Packages\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Core\Support\CorePackageSelectorSupport;
use Symfony\Component\HttpFoundation\Response;

/**
 * GLOBAL (prepended): corre PRE-routing, antes de los grupos de ruta y del StartSession del
 * grupo web. Resuelve el package por subdominio y aplica sus overrides de config GLOBAL solo
 * en SUS requests.
 *
 * Convencion: un package overridea una clave del skeleton de Laravel creando un fichero con
 * ese nombre, p. ej. packages/Shop/config/app.php -> ['name' => '...']. El discovery lo carga
 * namespaced (shop.app) y aqui se fusiona (array_replace_recursive) sobre la global `app` —
 * solo declaras las claves que cambias. En centralizado la config global es unica por proceso;
 * esto la diverge en runtime (FPM share-nothing: el override muere al acabar el request).
 *
 * Stashea el package resuelto para que CoreResolvePackageFromHostMiddleware lo reuse sin re-query.
 */
class CoreApplyPackageConfigMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $subdomain = CorePackageSelectorSupport::subdomainFromHost($request->getHost());

        // Apex/localhost (null) y el operador ('commander') no tienen package -> no overridean.
        if ($subdomain !== null && strcasecmp($subdomain, 'commander') !== 0) {
            $package = $this->resolve($subdomain);

            if ($package !== null) {
                $request->attributes->set('core_active_package', $package);
                $this->applyOverrides(Str::lower($package->name));
            }
        }

        return $next($request);
    }

    /** Fusiona los config del package que coinciden con una clave del skeleton sobre la global. */
    private function applyOverrides(string $package): void
    {
        foreach ($this->globalConfigKeys() as $key) {
            $override = config("{$package}.{$key}");

            if (is_array($override) && $override !== []) {
                config([$key => array_replace_recursive((array) config($key, []), $override)]);
            }
        }
    }

    /** Claves del skeleton de Laravel (config/*.php): las unicas que un package puede overridear. */
    private function globalConfigKeys(): array
    {
        return array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            glob(config_path('*.php')) ?: [],
        );
    }

    private function resolve(string $subdomain): ?CommanderPackageModel
    {
        try {
            return CommanderPackageModel::query()->where('subdomain', $subdomain)->first();
        } catch (\Throwable) {
            return null; // tabla aun no migrada / sin BD: nunca rompe el request
        }
    }
}
