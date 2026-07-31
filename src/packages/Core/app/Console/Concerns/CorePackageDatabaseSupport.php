<?php

namespace Packages\Core\Console\Concerns;

use Illuminate\Support\Str;
use Packages\Core\Support\CorePackageDiscoverySupport;
use Symfony\Component\Console\Input\InputOption;

trait CorePackageDatabaseSupport
{
    protected function coreWithPackageDatabaseOption(array $options): array
    {
        $hasClient = $hasPackage = false;

        foreach ($options as $option) {
            $hasClient = $hasClient || ($option[0] ?? null) === 'client';
            $hasPackage = $hasPackage || ($option[0] ?? null) === 'package';
        }

        if (! $hasClient) {
            $options[] = ['client', null, InputOption::VALUE_REQUIRED, 'Cliente del paquete (e.g. Acme). Requiere --package.'];
        }

        if (! $hasPackage) {
            $options[] = ['package', null, InputOption::VALUE_REQUIRED, 'Paquete dentro del cliente (e.g. Shop). Requiere --client.'];
        }

        return $options;
    }

    /**
     * Resuelve (--client, --package) del paquete anidado.
     *
     * @return array{0: string, 1: string} [clientStudly, packageStudly]
     */
    protected function resolvePackageForConsole(): array
    {
        $rawClient = $this->option('client');
        $rawPackage = $this->option('package');

        if (! is_string($rawClient) || trim($rawClient) === '' || ! is_string($rawPackage) || trim($rawPackage) === '') {
            $this->fail('--client y --package son obligatorios (e.g. --client=acme --package=Shop).');
        }

        $client = Str::studly(trim($rawClient));
        $package = Str::studly(trim($rawPackage));

        $discovery = CorePackageDiscoverySupport::fromEnvironment(base_path('packages'));
        if (! $discovery->hasImplementation($client, $package)) {
            $this->fail("No shared or client-specific implementation exists for [{$client}/{$package}].");
        }

        return [$client, $package];
    }
}
