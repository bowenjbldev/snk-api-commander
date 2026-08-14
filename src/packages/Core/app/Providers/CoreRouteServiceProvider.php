<?php

namespace Packages\Core\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Packages\Core\Http\Middleware\CoreEnsureActiveMiddleware;
use Packages\Core\Http\Middleware\CoreMeteringMiddleware;
use Packages\Core\Http\Middleware\CoreResolveInstanceFromHostMiddleware;
use Packages\Core\Support\CoreActiveInstanceSupport;
use Packages\Core\Support\CorePackageDiscoverySupport;
use Packages\Core\Support\CorePackageSelectorSupport;

class CoreRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        $this->configureRateLimiting();
        $this->configureMiddlewares();
        $this->configureRoutes();
    }

    private function configureRateLimiting(): void
    {
        // API consumers may fan out several requests, so the per-user budget is generous and
        // environment-tunable.
        RateLimiter::for('api', function (Request $request) {
            $tenant = $this->app->make(CoreActiveInstanceSupport::class)->id() ?? 'control';
            $actor = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute((int) env('CORE_API_RATE_LIMIT', 240))->by("{$tenant}:{$actor}");
        });

        RateLimiter::for('login', function (Request $request) {
            $tenant = $this->app->make(CoreActiveInstanceSupport::class)->id() ?? 'control';

            return Limit::perMinute((int) env('CORE_LOGIN_RATE_LIMIT', 10))->by("{$tenant}:{$request->ip()}");
        });
    }

    private function configureMiddlewares(): void
    {
        $router = $this->app->make(Router::class);

        // El override de config por package YA NO es global pre-routing: con subdominio = cliente
        // (varios packages comparten host), el package solo se conoce con el slug del path. El
        // override vive ahora dentro del resolutor de instancia, una vez resuelto
        // (cliente, slug) -> instancia. API stateless (sin sesión), así que no necesita
        // aplicarse antes del StartSession.

        $router->pushMiddlewareToGroup('api', 'throttle:api');

        // Metering + kill-switch son el commander del tenant: solo en deploys gestionados.
        // El control plane (no tenant) lo apaga y no los engancha.
        if (config('core.commander', true)) {
            // Metering el primero (outermost): registra tambien los 429 del throttle y los 402 del kill-switch.
            $router->prependMiddlewareToGroup('api', CoreMeteringMiddleware::class);
            // Kill-switch tras throttle y antes del auth de ruta.
            $router->pushMiddlewareToGroup('api', CoreEnsureActiveMiddleware::class);
        }
    }

    private function configureRoutes(): void
    {
        $this->app->booted(function (): void {
            $discovery = CorePackageDiscoverySupport::fromEnvironment(base_path('packages'));

            $coreFiles = [];
            $packageFiles = []; // module => route file

            foreach ($discovery->discoverRouteFiles() as $routeFile) {
                $module = $discovery->moduleNameFromRouteFile($routeFile);

                if (strcasecmp($module, 'Core') === 0) {
                    $coreFiles[] = $routeFile;

                    continue;
                }

                $packageFiles[$module] = $routeFile;
            }

            // Commander conserva su dominio privado; los tenants pueden usar otro dominio de marca.
            $base = (string) config('core.base_domain');
            $tenantBase = (string) config('core.tenant_base_domain', $base);

            // Rutas de Core (health/docs) GLOBALES: cualquier host (api.{BASE_DOMAIN}, localhost,
            // monitorizacion). Son agnosticas de package; el resolutor no aplica. El health queda
            // exento del kill-switch (core.state.exempt_paths) y no se mide (metering skip sin package).
            foreach ($coreFiles as $coreFile) {
                Route::prefix('api/v1')
                    ->middleware(['api'])
                    ->group($coreFile);
            }

            foreach ($packageFiles as $module => $routeFile) {
                // Commander (operador): subdominio fijo, SIN resolutor (no es tenant).
                if (strcasecmp($module, 'Commander') === 0) {
                    $namePrefix = 'commander.';
                    Route::domain('commander.'.$base)
                        ->prefix('api/v1')
                        ->name($namePrefix)
                        ->middleware(['api'])
                        ->group($routeFile);
                    $this->removeEmptyGroupNames($namePrefix);

                    continue;
                }

                $segments = explode('\\', $module);
                if (count($segments) !== 2) {
                    continue;
                }

                [$clientSeg, $packageSeg] = $segments;
                $clientSubdomain = CorePackageSelectorSupport::packageToSubdomain($clientSeg);
                $packageSlug = CorePackageSelectorSupport::packageToSubdomain($packageSeg);

                // Primero los overlays concretos. Si no definen una ruta, la ruta wildcard del
                // package compartido registrada después sigue siendo el fallback.
                $namePrefix = strtolower(str_replace('\\', '.', $module)).'.';
                Route::domain($clientSubdomain.'.'.$tenantBase)
                    ->prefix('api/v1/'.$packageSlug)
                    ->name($namePrefix)
                    ->middleware([CoreResolveInstanceFromHostMiddleware::class.':'.$packageSlug, 'api'])
                    ->group($routeFile);
                $this->removeEmptyGroupNames($namePrefix);
            }

            foreach ($packageFiles as $module => $routeFile) {
                $segments = explode('\\', $module);
                if (count($segments) !== 1 || strcasecmp($module, 'Commander') === 0) {
                    continue;
                }

                $packageSlug = CorePackageSelectorSupport::packageToSubdomain($module);

                $namePrefix = $packageSlug.'.';
                Route::domain('{client}.'.$tenantBase)
                    ->prefix('api/v1/'.$packageSlug)
                    ->name($namePrefix)
                    ->middleware([CoreResolveInstanceFromHostMiddleware::class.':'.$packageSlug, 'api'])
                    ->group($routeFile);
                $this->removeEmptyGroupNames($namePrefix);
            }
        });
    }

    private function removeEmptyGroupNames(string $prefix): void
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->getName() !== $prefix) {
                continue;
            }

            $action = $route->getAction();
            unset($action['as']);
            $route->setAction($action);
        }
    }
}
