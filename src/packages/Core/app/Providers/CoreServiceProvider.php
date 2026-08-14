<?php

namespace Packages\Core\Providers;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use Packages\Core\Support\CoreActiveInstanceSupport;
use Packages\Core\Support\CorePackageDiscoverySupport;

class CoreServiceProvider extends ServiceProvider
{
    private ?CorePackageDiscoverySupport $packageDiscovery = null;

    public function register(): void
    {
        // 1. Active package config must exist before any discovery runs.
        $this->mergeConfigFrom(base_path('packages/Core/config/core.php'), 'core');

        // Per-request active instance (set by CoreResolveInstanceFromHostMiddleware), shared by
        // the state service, metering and quota measurer. Singleton = one value per request.
        $this->app->singleton(CoreActiveInstanceSupport::class);

        // Multi-tenant: la cache de permisos de Spatie es por-request ('array'), no persistente.
        // Con BD por instancia, una cache compartida (file) filtraria los permisos de un tenant a
        // otra conexion; 'array' fuerza recargar de la conexion activa en cada request.
        config(['permission.cache.store' => 'array']);

        // 2. Core Framework Overrides
        $this->app->afterResolving(ExceptionHandlerContract::class, function (ExceptionHandlerContract $handler): void {
            if (! $handler instanceof ExceptionHandler) {
                return;
            }

            $handler->shouldRenderJsonWhen(function () {
                return true;
            });
        });

        // 3. Discover and Merge Package Configurations
        foreach ($this->discoverPackageConfigFiles() as $key => $paths) {
            foreach (Arr::wrap($paths) as $path) {
                if ($this->isGlobalConfigKey($key)) {
                    $this->overrideConfigFrom($path, $key);

                    continue;
                }

                $this->mergeConfigFrom($path, $key);
            }
        }

        // 4. Register Internal Core Providers
        $this->app->register(CoreScrambleServiceProvider::class);
        $this->app->register(CoreRouteServiceProvider::class);
        $this->app->register(CoreConsoleServiceProvider::class);

        // 5. Register External Package Providers
        foreach ($this->discoverPackageProviders() as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        // Migraciones del deploy COMMANDER (conexion default = commander): base del operador
        // (roles/users/tokens) + registro + metering + operador (commander_*). Las de NEGOCIO
        // de cada package NO se auto-cargan: las corre el provisioning sobre la conexion del tenant
        // (core:migrate --package=Pkg), nunca sobre la commander.
        $paths = array_filter([
            base_path('packages/Core/database/migrations'),
            base_path('packages/Commander/database/migrations'),
        ], 'is_dir');

        if ($paths !== []) {
            $this->loadMigrationsFrom($paths);
        }
    }

    /**
     * @return array<class-string<ServiceProvider>>
     */
    private function discoverPackageProviders(): array
    {
        return $this->packageDiscovery()->discoverProviderClasses();
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    private function discoverPackageConfigFiles(): array
    {
        return $this->packageDiscovery()->discoverConfigFiles();
    }

    private function overrideConfigFrom(string $path, string $key): void
    {
        if ($this->app instanceof CachesConfiguration &&
            $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');

        $config->set($key, array_replace_recursive(
            $config->get($key, []),
            require $path
        ));
    }

    /**
     * @return string[]
     */
    private function discoverPackageMigrationPaths(): array
    {
        return $this->packageDiscovery()->discoverMigrationPaths();
    }

    private function packageDiscovery(): CorePackageDiscoverySupport
    {
        if ($this->packageDiscovery === null) {
            $this->packageDiscovery = CorePackageDiscoverySupport::fromEnvironment(base_path('packages'));
        }

        return $this->packageDiscovery;
    }

    private function isGlobalConfigKey(string $key): bool
    {
        return ! str_contains($key, '.');
    }
}
