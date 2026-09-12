<?php

namespace Packages\Core\Support;

use Illuminate\Database\Seeder;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

final class CorePackageDiscoverySupport
{
    /** Subdirectorios de recurso que marcan el fin del namespace del módulo. */
    private const RESOURCE_DIRS = ['app', 'config', 'routes', 'database'];

    private string $packagesBasePath;

    /**
     * @var string[]|null
     */
    private ?array $globalConfigKeys = null;

    public function __construct(string $packagesBasePath)
    {
        $this->packagesBasePath = rtrim($packagesBasePath, '/');
    }

    public static function fromEnvironment(string $packagesBasePath): self
    {
        return new self($packagesBasePath);
    }

    /**
     * @return array<class-string<ServiceProvider>>
     */
    public function discoverProviderClasses(): array
    {
        $paths = $this->filterByLoadableModule($this->globModules('/app/Providers/*ServiceProvider.php'), false);

        $providers = [];

        foreach ($paths as $path) {
            $module = $this->moduleNamespace($path);            // Core | Acme\Shop

            // Los providers de overlays de cliente no se cargan globalmente: un binding de
            // ElMundo no puede modificar las peticiones de ElConfidencial. El overlay sigue
            // pudiendo aportar rutas, config, migraciones y seeders acotados a su tenant.
            if (str_contains($module, '\\')) {
                continue;
            }

            $expectedClass = $this->classBase($module).'ServiceProvider'; // AcmeShopServiceProvider
            $class = pathinfo($path, PATHINFO_FILENAME);

            if ($class !== $expectedClass) {
                continue;
            }

            $fqcn = "Packages\\{$module}\\Providers\\{$class}";

            if (class_exists($fqcn)) {
                $providers[] = $fqcn;
            }
        }

        return array_values(array_unique($providers));
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    public function discoverConfigFiles(): array
    {
        $paths = $this->filterByLoadableModule($this->globModules('/config/*.php'), true);

        $configs = [];

        foreach ($paths as $path) {
            $fileKey = pathinfo($path, PATHINFO_FILENAME);
            $moduleKey = $this->moduleConfigKey($path); // core | acme.shop

            // Core/config/core.php lo mergea CoreServiceProvider de forma explícita.
            if ($moduleKey === 'core' && $fileKey === 'core') {
                continue;
            }
            // Solo Core puede escribir claves globales de Laravel (auth, cors, scramble...).
            // Con TODOS los packages cargados a la vez, permitir overrides globales desde
            // packages de negocio causaría colisiones no deterministas (gana el último).
            // Sus configs van SIEMPRE namespaced (acme.shop.sanctum, acme.shop.scramble...).
            $key = ($moduleKey === 'core' && $this->shouldUseGlobalConfigKey($fileKey))
                ? $fileKey
                : $moduleKey.'.'.$fileKey;

            if (! array_key_exists($key, $configs)) {
                $configs[$key] = $path;

                continue;
            }

            if (is_array($configs[$key])) {
                $configs[$key][] = $path;

                continue;
            }

            $configs[$key] = [$configs[$key], $path];
        }

        foreach ($configs as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $configs[$key] = $this->sortConfigPathsForKey($key, $value);
        }

        return $configs;
    }

    /**
     * @return string[]
     */
    public function discoverRouteFiles(): array
    {
        return $this->filterByLoadableModule($this->globModules('/routes/api.php'), true);
    }

    /**
     * Rutas fuera de la API que un package sirve bajo el host del inquilino, sin prefijo: lo que
     * un navegador pide directamente a un periódico y no es un endpoint (derivadas de imagen,
     * por ejemplo). Genérico: cualquier package con `routes/web.php` entra.
     *
     * @return string[]
     */
    public function discoverWebRouteFiles(): array
    {
        return $this->filterByLoadableModule($this->globModules('/routes/web.php'), true);
    }

    /**
     * @return string[]
     */
    public function discoverMigrationPaths(): array
    {
        return $this->filterByLoadableModule($this->globModules('/database/migrations'), true);
    }

    /**
     * @return array<class-string<Seeder>>
     */
    public function discoverSeederClasses(?string $excludeClass = null): array
    {
        $paths = $this->filterByLoadableModule($this->globModules('/database/seeders/*DatabaseSeeder.php'), true);

        $seeders = [];

        foreach ($paths as $path) {
            $module = $this->moduleNamespace($path);
            $class = pathinfo($path, PATHINFO_FILENAME);
            $fqcn = "Packages\\{$module}\\Database\\Seeders\\{$class}";

            if ($excludeClass !== null && $fqcn === $excludeClass) {
                continue;
            }

            if (class_exists($fqcn)) {
                $seeders[] = $fqcn;
            }
        }

        return array_values(array_unique($seeders));
    }

    /** Namespace del módulo de un route file: Core | Commander | Acme\Shop. */
    public function moduleNameFromRouteFile(string $routeFilePath): string
    {
        return $this->moduleNamespace($routeFilePath);
    }

    /**
     * Implementaciones aplicables a una instancia, en orden base -> overlay de cliente.
     *
     * @return string[] módulos: Kilkana, ElMundo\Kilkana
     */
    public function implementationModules(string $client, string $package): array
    {
        $client = Str::studly($client);
        $package = Str::studly($package);

        if ($client === '' || $package === '' || in_array($package, ['Core', 'Commander'], true)) {
            return [];
        }

        $modules = [$package, "{$client}\\{$package}"];
        if (! is_file($this->modulePath($modules[0]).'/routes/api.php')
            && ! is_file($this->modulePath($modules[1]).'/routes/api.php')) {
            return [];
        }

        return array_values(array_filter(
            $modules,
            fn (string $module): bool => is_dir($this->modulePath($module))
                && $this->containsModuleResources($this->modulePath($module)),
        ));
    }

    /** @return string[] rutas absolutas, base -> overlay */
    public function implementationDirectories(string $client, string $package): array
    {
        return array_map($this->modulePath(...), $this->implementationModules($client, $package));
    }

    public function hasImplementation(string $client, string $package): bool
    {
        return $this->implementationModules($client, $package) !== [];
    }

    /** @return string[] tipos de negocio desplegados, compartidos o específicos */
    public function availableBusinessPackages(): array
    {
        $names = [];
        $candidates = array_merge(
            glob($this->packagesBasePath.'/*', GLOB_ONLYDIR) ?: [],
            glob($this->packagesBasePath.'/*/*', GLOB_ONLYDIR) ?: [],
        );

        foreach ($candidates as $directory) {
            $relative = ltrim(substr($directory, strlen($this->packagesBasePath)), '/');
            $segments = explode('/', $relative);

            if (count($segments) === 1) {
                if (in_array($segments[0], ['Core', 'Commander'], true)
                    || ! is_file("{$directory}/routes/api.php")) {
                    continue;
                }
            } elseif (count($segments) !== 2 || ! is_file("{$directory}/routes/api.php")) {
                continue;
            }

            $names[] = end($segments);
        }

        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Glob de un recurso en packages de framework (1 nivel: packages/Core/...) Y de negocio
     * anidados por cliente (2 niveles: packages/Acme/Shop/...). Las carpetas paraguas de cliente
     * (packages/Acme) no tienen recursos propios, así que no colisionan.
     *
     * @return string[]
     */
    private function globModules(string $tail): array
    {
        if (! is_dir($this->packagesBasePath)) {
            return [];
        }

        $paths = array_merge(
            glob($this->packagesBasePath.'/*'.$tail) ?: [],
            glob($this->packagesBasePath.'/*/*'.$tail) ?: [],
        );

        sort($paths, SORT_STRING);

        return $paths;
    }

    private function modulePath(string $module): string
    {
        return $this->packagesBasePath.'/'.str_replace('\\', '/', $module);
    }

    private function containsModuleResources(string $directory): bool
    {
        foreach (['routes/*.php', 'config/*.php', 'database/migrations/*.php', 'database/seeders/*.php'] as $pattern) {
            if ((glob("{$directory}/{$pattern}") ?: []) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string[]  $paths
     * @return string[]
     */
    private function filterByLoadableModule(array $paths, bool $allowCore): array
    {
        // Siempre centralizado: se cargan todos los packages. Solo se excluye Core cuando el
        // llamante lo pide (su provider/recursos se registran explícitamente, no por descubrimiento).
        if ($allowCore) {
            return array_values($paths);
        }

        return array_values(array_filter(
            $paths,
            fn (string $path): bool => strcasecmp($this->moduleNamespace($path), 'Core') !== 0,
        ));
    }

    /** Namespace del módulo a partir de la ruta de un recurso: lo que va entre packages/ y app|config|routes|database. */
    private function moduleNamespace(string $path): string
    {
        $rel = ltrim(substr($path, strlen($this->packagesBasePath)), '/');
        $segments = explode('/', $rel);
        $module = [];

        foreach ($segments as $segment) {
            if (in_array($segment, self::RESOURCE_DIRS, true)) {
                break;
            }

            $module[] = $segment;
        }

        return implode('\\', $module);
    }

    /** Clave de config namespaced del módulo: Core -> core; Acme\Shop -> acme.shop. */
    private function moduleConfigKey(string $path): string
    {
        return strtolower(str_replace('\\', '.', $this->moduleNamespace($path)));
    }

    /** Base de clase (sin namespace) del módulo: Core -> Core; Acme\Shop -> AcmeShop. */
    private function classBase(string $module): string
    {
        return str_replace('\\', '', $module);
    }

    private function shouldUseGlobalConfigKey(string $fileKey): bool
    {
        return in_array($fileKey, $this->discoverGlobalConfigKeys(), true);
    }

    /**
     * Detecta en tiempo de ejecución las claves de config del esqueleto y del framework de Laravel.
     *
     * @return string[]
     */
    private function discoverGlobalConfigKeys(): array
    {
        if (is_array($this->globalConfigKeys)) {
            return $this->globalConfigKeys;
        }

        $projectRoot = dirname($this->packagesBasePath);
        $projectConfigPath = $projectRoot.'/config';
        $paths = is_dir($projectConfigPath)
            ? (glob($projectConfigPath.'/*.php') ?: [])
            : [];

        sort($paths, SORT_STRING);

        $keys = [];

        foreach ($paths as $path) {
            $key = pathinfo($path, PATHINFO_FILENAME);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        $this->globalConfigKeys = array_values(array_unique($keys));

        return $this->globalConfigKeys;
    }

    /**
     * @param  string[]  $paths
     * @return string[]
     */
    private function sortConfigPathsForKey(string $key, array $paths): array
    {
        if (! str_contains($key, '.')) {
            usort($paths, function (string $a, string $b): int {
                $aIsCore = strcasecmp($this->moduleNamespace($a), 'Core') === 0;
                $bIsCore = strcasecmp($this->moduleNamespace($b), 'Core') === 0;

                if ($aIsCore !== $bIsCore) {
                    return $aIsCore ? -1 : 1;
                }

                return strcmp($a, $b);
            });
        }

        return $paths;
    }
}
