<?php

namespace Packages\Core\Console\Concerns;

use Illuminate\Support\Str;
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

        $options[] = ['package', null, InputOption::VALUE_REQUIRED, 'Target package (e.g. Shop)'];

        return $options;
    }

    protected function resolvePackageForConsole(): string
    {
        $raw = $this->option('package');

        if (!is_string($raw) || trim($raw) === '') {
            $this->fail('--package is required (e.g. --package=Shop).');
        }

        $package = Str::studly(trim($raw));

        if (!is_dir(base_path("packages/{$package}"))) {
            $this->fail("Package [{$package}] does not exist at " . base_path("packages/{$package}") . '.');
        }

        return $package;
    }
}
