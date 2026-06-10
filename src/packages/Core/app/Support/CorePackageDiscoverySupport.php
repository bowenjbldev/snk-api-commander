<?php

namespace Packages\Core\Support;

final class CorePackageDiscoverySupport
{
    private string $packagesBasePath;
    private CorePackageSelectorSupport $selector;
    /**
     * @var string[]|null
     */
    private ?array $globalConfigKeys = null;

    public function __construct(string $packagesBasePath, ?CorePackageSelectorSupport $selector = null)
    {
        $this->packagesBasePath = $packagesBasePath;
        $this->selector = $selector ?? CorePackageSelectorSupport::fromEnvironment($packagesBasePath);
    }

    public static function fromEnvironment(string $packagesBasePath): self
    {
        return new self($packagesBasePath);
    }

    /**
     * @return array<class-string<\Illuminate\Support\ServiceProvider>>
     */
    public function discoverProviderClasses(): array
    {
        $paths = $this->globSorted('/*/app/Providers/*ServiceProvider.php');
        $paths = $this->filterPathsByLoadableModule($paths, 3, false);

        $providers = [];

        foreach ($paths as $path) {
            $module = $this->moduleFromPath($path, 3);
            $expectedClass = $module . 'ServiceProvider';
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
        $paths = $this->globSorted('/*/config/*.php');
        $paths = $this->filterPathsByLoadableModule($paths, 2);

        $configs = [];

        foreach ($paths as $path) {
            $fileKey = pathinfo($path, PATHINFO_FILENAME);
            $moduleKey = strtolower($this->moduleFromPath($path, 2));

            // Core/config/core.php is merged explicitly by CoreServiceProvider.
            if ($moduleKey === 'core' && $fileKey === 'core') {
                continue;
            }
            $key = $this->shouldUseGlobalConfigKey($fileKey)
                ? $fileKey
                : $moduleKey . '.' . $fileKey;

            if (!array_key_exists($key, $configs)) {
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
            if (!is_array($value)) {
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
        $paths = $this->globSorted('/*/routes/api.php');

        return $this->filterPathsByLoadableModule($paths, 2);
    }

    /**
     * @return string[]
     */
    public function discoverMigrationPaths(): array
    {
        $paths = $this->globSorted('/*/database/migrations');

        return $this->filterPathsByLoadableModule($paths, 2);
    }

    /**
     * @return array<class-string<\Illuminate\Database\Seeder>>
     */
    public function discoverSeederClasses(?string $excludeClass = null): array
    {
        $paths = $this->globSorted('/*/database/seeders/*DatabaseSeeder.php');
        $paths = $this->filterPathsByLoadableModule($paths, 3);

        $seeders = [];

        foreach ($paths as $path) {
            $module = $this->moduleFromPath($path, 3);
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

    public function moduleNameFromRouteFile(string $routeFilePath): string
    {
        return $this->moduleFromPath($routeFilePath, 2);
    }

    /**
     * @return string[]
     */
    private function globSorted(string $pattern): array
    {
        if (!is_dir($this->packagesBasePath)) {
            return [];
        }

        $paths = glob($this->packagesBasePath . $pattern) ?: [];
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @param string[] $paths
     * @return string[]
     */
    private function filterPathsByLoadableModule(array $paths, int $levelsUp, bool $allowCore = true): array
    {
        return array_values(array_filter($paths, function (string $path) use ($levelsUp, $allowCore): bool {
            $module = $this->moduleFromPath($path, $levelsUp);

            if (!$allowCore && strcasecmp($module, 'Core') === 0) {
                return false;
            }

            return $this->selector->shouldLoad($module);
        }));
    }

    private function moduleFromPath(string $path, int $levelsUp): string
    {
        $target = $path;

        for ($index = 0; $index < $levelsUp; $index += 1) {
            $target = dirname($target);
        }

        return basename($target);
    }

    private function shouldUseGlobalConfigKey(string $fileKey): bool
    {
        return in_array($fileKey, $this->discoverGlobalConfigKeys(), true);
    }

    /**
     * Detect Laravel skeleton/framework config keys dynamically.
     *
     * @return string[]
     */
    private function discoverGlobalConfigKeys(): array
    {
        if (is_array($this->globalConfigKeys)) {
            return $this->globalConfigKeys;
        }

        $projectRoot = dirname($this->packagesBasePath);
        $projectConfigPath = $projectRoot . '/config';
        $paths = is_dir($projectConfigPath)
            ? (glob($projectConfigPath . '/*.php') ?: [])
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
     * @param string[] $paths
     * @return string[]
     */
    private function sortConfigPathsForKey(string $key, array $paths): array
    {
        if (!str_contains($key, '.')) {
            usort($paths, function (string $a, string $b): int {
                $moduleA = $this->moduleFromPath($a, 2);
                $moduleB = $this->moduleFromPath($b, 2);
                $aIsCore = strcasecmp($moduleA, 'Core') === 0;
                $bIsCore = strcasecmp($moduleB, 'Core') === 0;

                if ($aIsCore !== $bIsCore) {
                    return $aIsCore ? -1 : 1;
                }

                return strcmp($a, $b);
            });
        }

        return $paths;
    }
}
