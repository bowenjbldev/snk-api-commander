<?php

namespace Packages\Core\Console\Concerns;

use Illuminate\Support\Str;
use Packages\Core\Support\CorePackageSelectorSupport;
use Symfony\Component\Console\Input\InputOption;

trait CorePackageDatabaseSupport
{
    protected function coreWithPackageDatabaseOption(array $options): array
    {
        foreach ($options as $option) {
            if (($option[0] ?? null) === 'package') {
                return $options;
            }
        }

        $options[] = ['package', null, InputOption::VALUE_REQUIRED, 'Target package (defaults to API_PACKAGE, e.g. Shop)'];

        return $options;
    }

    protected function resolvePackageForConsole(): string
    {
        $selector = CorePackageSelectorSupport::fromEnvironment(base_path('packages'));
        $active = $selector->activePackage();

        $raw = $this->option('package');

        if (is_string($raw) && trim($raw) !== '') {
            $package = Str::studly(trim($raw));

            if ($active !== null && strcasecmp($package, $active) !== 0) {
                $this->fail("--package [{$package}] does not match API_PACKAGE [{$active}]. This deployment only serves one package.");
            }

            $path = base_path("packages/{$package}");

            if (!is_dir($path)) {
                $this->fail("Package [{$package}] does not exist at {$path}.");
            }

            return $package;
        }

        if ($active !== null) {
            return $active;
        }

        $this->fail('API_PACKAGE is not set in .env and no --package option was provided.');
    }
}
