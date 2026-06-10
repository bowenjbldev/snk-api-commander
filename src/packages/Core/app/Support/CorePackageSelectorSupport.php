<?php

namespace Packages\Core\Support;

use Illuminate\Support\Str;
use Packages\Core\Exceptions\CorePackageConfigurationException;

final class CorePackageSelectorSupport
{
    private string $packagesBasePath;

    public function __construct(string $packagesBasePath)
    {
        $this->packagesBasePath = $packagesBasePath;
    }

    public static function fromEnvironment(string $packagesBasePath): self
    {
        return new self($packagesBasePath);
    }

    /**
     * Active package name from config/env (API_PACKAGE), without validation.
     */
    public static function activePackageName(): ?string
    {
        $raw = config('core.package') ?? env('API_PACKAGE');

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        return Str::studly(trim($raw));
    }

    /**
     * Active package name, validated against the packages directory.
     *
     * @throws CorePackageConfigurationException when API_PACKAGE names a missing package
     */
    public function activePackage(): ?string
    {
        $package = self::activePackageName();

        if ($package === null) {
            return null;
        }

        $path = $this->packagesBasePath . '/' . $package;

        if (!is_dir($path)) {
            throw CorePackageConfigurationException::missingPackage($package, $path);
        }

        return $package;
    }

    public function shouldLoad(string $module): bool
    {
        if (strcasecmp($module, 'Core') === 0) {
            return true;
        }

        $active = self::activePackageName();

        return $active !== null && strcasecmp($module, $active) === 0;
    }

    /**
     * @return string[]
     */
    public static function allPackageNames(string $packagesBasePath): array
    {
        if (!is_dir($packagesBasePath)) {
            return [];
        }

        $modules = glob($packagesBasePath . '/*', GLOB_ONLYDIR) ?: [];
        $names = [];

        foreach ($modules as $modulePath) {
            $module = basename($modulePath);

            if (strcasecmp($module, 'Core') === 0) {
                continue;
            }

            $names[] = $module;
        }

        sort($names, SORT_STRING);

        return $names;
    }
}
