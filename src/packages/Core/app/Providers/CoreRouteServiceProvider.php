<?php

namespace Packages\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use Packages\Core\Http\Middleware\CoreResolvePackageFromHostMiddleware;
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
        // SPA dashboards fan out several requests per navigation (loadData + per-page usage),
        // so the per-user budget is generous and env-tunable.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) env('CORE_API_RATE_LIMIT', 240))->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute((int) env('CORE_LOGIN_RATE_LIMIT', 10))->by($request->ip());
        });
    }

    private function configureMiddlewares(): void
    {
        $router = $this->app->make(Router::class);

        // GLOBAL (pre-routing): aplica los overrides de config global del package del subdominio
        // antes del routing y del StartSession. Se engancha en el Kernel HTTP desde Core (no se
        // toca el skeleton bootstrap/app.php). Ver packages/{Pkg}/config/overrides.php.
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class)
            ->prependMiddleware(\Packages\Core\Http\Middleware\CoreApplyPackageConfigMiddleware::class);

        $router->pushMiddlewareToGroup('api', 'throttle:api');

        // Metering + kill-switch son el commander del tenant: solo en deploys gestionados.
        // El panel del operador (no tenant) lo apaga y no los engancha.
        if (config('core.commander', true)) {
            // Metering el primero (outermost): registra tambien los 429 del throttle y los 402 del kill-switch.
            $router->prependMiddlewareToGroup('api', \Packages\Core\Http\Middleware\CoreMeteringMiddleware::class);
            // Kill-switch tras throttle y antes del auth de ruta.
            $router->pushMiddlewareToGroup('api', \Packages\Core\Http\Middleware\CoreEnsureActiveMiddleware::class);
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

            // Centralizado: cada package bajo su propio subdominio {sub}.{BASE_DOMAIN}.
            $base = (string) config('core.base_domain');

            // Rutas de Core (health/docs) GLOBALES: cualquier host (api.{BASE_DOMAIN}, localhost,
            // monitorizacion). Son agnosticas de package; el resolutor no aplica. El health queda
            // exento del kill-switch (core.state.exempt_paths) y no se mide (metering skip sin package).
            foreach ($coreFiles as $coreFile) {
                Route::prefix('api/v1')
                    ->middleware(['api'])
                    ->group($coreFile);
            }

            foreach ($packageFiles as $module => $routeFile) {
                $subdomain = CorePackageSelectorSupport::packageToSubdomain($module);
                $isCommander = strcasecmp($module, 'Commander') === 0;

                // Solo las apps de negocio resuelven package por host. El Commander (operador)
                // no es un tenant: sin resolutor (su contexto queda sin package activo).
                $middleware = $isCommander
                    ? ['api']
                    : [CoreResolvePackageFromHostMiddleware::class, 'api'];

                Route::domain($subdomain . '.' . $base)
                    ->prefix('api/v1')
                    ->middleware($middleware)
                    ->group($routeFile);
            }
        });
    }
}
