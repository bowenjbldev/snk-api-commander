<?php

namespace Packages\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use Packages\Core\Support\CorePackageDiscoverySupport;

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

        // Metering el primero (outermost): registra tambien los 429 del
        // throttle y los 402 del kill-switch.
        $router->prependMiddlewareToGroup('api', \Packages\Core\Http\Middleware\CoreMeteringMiddleware::class);
        $router->pushMiddlewareToGroup('api', 'throttle:api');
        // Kill-switch tras throttle y antes del auth de ruta.
        $router->pushMiddlewareToGroup('api', \Packages\Core\Http\Middleware\CoreEnsureActiveMiddleware::class);
    }

    private function configureRoutes(): void
    {
        $this->app->booted(function (): void {
            $discovery = CorePackageDiscoverySupport::fromEnvironment(base_path('packages'));

            $coreFiles = [];
            $packageFiles = [];

            foreach ($discovery->discoverRouteFiles() as $routeFile) {
                $module = $discovery->moduleNameFromRouteFile($routeFile);

                if (strcasecmp($module, 'Core') === 0) {
                    $coreFiles[] = $routeFile;
                    continue;
                }

                $packageFiles[] = $routeFile;
            }

            // Core first, active package last: on identical method+URI the package wins.
            foreach ([...$coreFiles, ...$packageFiles] as $routeFile) {
                Route::prefix('api/v1')
                    ->middleware(['api'])
                    ->group($routeFile);
            }
        });
    }
}
