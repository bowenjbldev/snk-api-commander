<?php

namespace Packages\Core\Providers;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Arr;
use Packages\Core\Support\CoreActivePackageSupport;
use Packages\Core\Support\CorePackageDiscoverySupport;
use Packages\Core\Support\CorePackageSelectorSupport;

class CoreServiceProvider extends ServiceProvider
{
    private ?CorePackageDiscoverySupport $packageDiscovery = null;

    public function register(): void
    {
        // 1. Active package config must exist before any discovery runs.
        $this->mergeConfigFrom(base_path('packages/Core/config/core.php'), 'core');

        // Per-request active package (set by CoreResolvePackageFromHostMiddleware), shared by
        // the state service, metering and quota measurer. Singleton = one value per request.
        $this->app->singleton(CoreActivePackageSupport::class);

        // Multi-tenant: la cache de permisos de Spatie es por-request ('array'), no persistente.
        // Con BD por package, una cache compartida (file) filtraria los permisos de un tenant a
        // otra conexion; 'array' fuerza recargar de la conexion activa en cada request.
        config(['permission.cache.store' => 'array']);

        // Fail loudly at boot (HTTP and artisan) if API_PACKAGE points to a missing package.
        CorePackageSelectorSupport::fromEnvironment(base_path('packages'))->activePackage();

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
        // Migraciones del deploy CENTRAL (conexion default = central): registro (packages) +
        // control-plane + commander_* + base del operador (roles/users/tokens). Las de NEGOCIO de
        // cada package NO se auto-cargan: las corre el provisioning sobre la conexion del tenant
        // (core:migrate --connection=tenant), nunca sobre la central.
        $paths = array_filter([
            base_path('packages/Core/database/migrations/central'),
            base_path('packages/Core/database/migrations/tenant'),
            base_path('packages/Commander/database/migrations'),
        ], 'is_dir');

        if ($paths !== []) {
            $this->loadMigrationsFrom($paths);
        }
    }

    /**
     * @return array<class-string<\Illuminate\Support\ServiceProvider>>
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
        if ($this->app instanceof \Illuminate\Contracts\Foundation\CachesConfiguration &&
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
        return !str_contains($key, '.');
    }
}

