<?php

namespace Packages\Core\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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
        // Leer una columna que la consulta no seleccionó pasa de devolver `null` en silencio a
        // reventar. Es lo que sostiene `?fields=`: con el recorte puesto, un `$post->excerpt`
        // sobre una fila que no lo trajo devolvía «vacío» en vez de «no lo pediste», y esa
        // diferencia no la ve nadie hasta que una pantalla pinta un hueco.
        //
        // Fuera de producción, igual que el anterior: en producción una respuesta a medias es
        // mejor que un 500, y aquí lo que se busca es que salte en la suite.
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());

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

            return Limit::perMinute((int) config('core.rate_limits.api_per_minute', 240))->by("{$tenant}:{$actor}");
        });

        RateLimiter::for('login', function (Request $request) {
            $tenant = $this->app->make(CoreActiveInstanceSupport::class)->id() ?? 'control';
            $input = $request->input('email');
            $email = is_string($input) ? Str::lower(trim($input)) : '';
            $failed = static fn ($response): bool => $response->getStatusCode() === 401;

            return [
                Limit::perMinute((int) config('core.rate_limits.login_account_failures_per_minute', 5))
                    ->by("{$tenant}:account:{$email}")
                    ->after($failed),
                Limit::perMinute((int) config('core.rate_limits.login_ip_failures_per_minute', 10))
                    ->by("{$tenant}:ip:{$request->ip()}")
                    ->after($failed),
            ];
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

            // El operador tiene su dominio privado; todas las APIs tenant comparten el dominio de plataforma.
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

            // Rutas web de los packages compartidos: bajo el host del inquilino, sin prefijo, con
            // el inquilino resuelto y sin el grupo `api` —ni sesión ni freno: lo que sirven es un
            // fichero que nginx cachea; aquí llega lo que aún no existe, y de serializar a los que
            // lo piden a la vez se encarga quien lo genera—.
            foreach ($discovery->discoverWebRouteFiles() as $webFile) {
                $module = $discovery->moduleNameFromRouteFile($webFile);
                if (str_contains($module, '\\') || in_array(strtolower($module), ['core', 'commander'], true)) {
                    continue;
                }
                $packageSlug = CorePackageSelectorSupport::packageToSubdomain($module);
                Route::domain('{client}.'.$tenantBase)
                    ->name($packageSlug.'.web.')
                    ->middleware([CoreResolveInstanceFromHostMiddleware::class.':'.$packageSlug])
                    ->group($webFile);
                $this->removeEmptyGroupNames($packageSlug.'.web.');
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
