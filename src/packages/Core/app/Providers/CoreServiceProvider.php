<?php

namespace Packages\Core\Providers;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Packages\Core\Models\CorePersonalAccessTokenModel;
use Packages\Core\Support\CoreActiveInstanceSupport;
use Packages\Core\Support\CoreMeteringBufferSupport;
use Packages\Core\Support\CorePackageDiscoverySupport;
use Packages\Core\Support\CorePermissionRegistrarSupport;
use Spatie\Permission\PermissionRegistrar;

class CoreServiceProvider extends ServiceProvider
{
    private ?CorePackageDiscoverySupport $packageDiscovery = null;

    public function register(): void
    {
        // La configuración del package activo debe existir antes de descubrir nada.
        $this->mergeConfigFrom(base_path('packages/Core/config/core.php'), 'core');

        // Instancia activa por petición (la fija CoreResolveInstanceFromHostMiddleware), compartida
        // por el servicio de estado, el metering y el medidor de cuota. Singleton = un valor por petición.
        $this->app->singleton(CoreActiveInstanceSupport::class);
        // Cola de metering por proceso: en Octane vive lo que vive el worker y escribe en lote.
        $this->app->singleton(CoreMeteringBufferSupport::class);

        // Multi-tenant: la cache de permisos de Spatie es por-request ('array'), no persistente.
        // Con BD por instancia, una cache compartida (file) filtraria los permisos de un tenant a
        // otra conexion; 'array' fuerza recargar de la conexion activa en cada request.
        config(['permission.cache.store' => 'array']);

        // Overrides del framework Core.
        $this->app->afterResolving(ExceptionHandlerContract::class, function (ExceptionHandlerContract $handler): void {
            if (! $handler instanceof ExceptionHandler) {
                return;
            }

            $handler->shouldRenderJsonWhen(function () {
                return true;
            });
        });

        // Descubrimiento y mezcla de configuraciones de packages.
        foreach ($this->discoverPackageConfigFiles() as $key => $paths) {
            foreach (Arr::wrap($paths) as $path) {
                if ($this->isGlobalConfigKey($key)) {
                    $this->overrideConfigFrom($path, $key);

                    continue;
                }

                $this->mergeConfigFrom($path, $key);
            }
        }

        // Providers internos de Core.
        $this->app->register(CoreScrambleServiceProvider::class);
        $this->app->register(CoreRouteServiceProvider::class);
        $this->app->register(CoreConsoleServiceProvider::class);

        // Providers de packages externos.
        foreach ($this->discoverPackageProviders() as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        // El registrar de Spatie con memoria por worker de la colección hidratada. En `boot()` y
        // no en `register()`: el provider de Spatie registra su singleton al arrancar y pisaría
        // lo atado antes; Spatie arranca antes que Core, así que esto es lo último que se ata.
        $this->app->singleton(PermissionRegistrar::class, CorePermissionRegistrarSupport::class);

        // El token de Sanctum con `last_used_at` escrito al minuto y no en cada petición.
        Sanctum::usePersonalAccessTokenModel(CorePersonalAccessTokenModel::class);

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
