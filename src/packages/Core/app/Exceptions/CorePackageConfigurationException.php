<?php

namespace Packages\Core\Exceptions;

use RuntimeException;

class CorePackageConfigurationException extends RuntimeException
{
    public static function missingPackage(string $package, string $path): self
    {
        return new self(
            "API_PACKAGE is set to [{$package}] but no package exists at [{$path}]. " .
            "Fix API_PACKAGE in .env or create the package with `php artisan make:package {$package}`."
        );
    }
}
