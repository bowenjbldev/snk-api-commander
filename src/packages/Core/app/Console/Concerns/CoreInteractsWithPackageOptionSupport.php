<?php

namespace Packages\Core\Console\Concerns;

use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

/**
 * Soporte de generadores para paquetes de negocio ANIDADOS por cliente: --client + --package
 * apuntan a packages/<Client>/<Package> (namespace Packages\Client\Package\, prefijo de clase N2
 * ClientPackage). Ambos juntos => paquete anidado; ninguno => base Laravel (app/); solo uno => error.
 */
trait CoreInteractsWithPackageOptionSupport
{
    private bool $coreTargetResolved = false;
    private ?string $coreClientName = null;   // Studly: Acme
    private ?string $corePackageName = null;  // Studly: Shop

    private function coreResolveTarget(): void
    {
        if ($this->coreTargetResolved) {
            return;
        }

        $this->coreTargetResolved = true;

        $rawClient = $this->option('client');
        $rawPackage = $this->option('package');
        $hasClient = is_string($rawClient) && trim($rawClient) !== '';
        $hasPackage = is_string($rawPackage) && trim($rawPackage) !== '';

        if (!$hasClient && !$hasPackage) {
            return; // sin target: generador base de Laravel (app/)
        }

        if (!$hasClient || !$hasPackage) {
            $this->fail('Un paquete anidado requiere --client Y --package (packages/<Client>/<Package>).');
        }

        $this->coreClientName = $this->coreStudlyName((string) $rawClient, 'client');
        $this->corePackageName = $this->coreStudlyName((string) $rawPackage, 'package');

        $path = base_path("packages/{$this->coreClientName}/{$this->corePackageName}");

        if (!is_dir($path)) {
            $this->fail("Package [{$this->coreClientName}/{$this->corePackageName}] does not exist in {$path}.");
        }
    }

    private function coreStudlyName(string $raw, string $what): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]+/', ' ', trim($raw)) ?? '';
        $studly = Str::studly($normalized);

        if ($studly === '' || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $studly)) {
            $this->fail("Invalid {$what} name provided in --{$what}.");
        }

        return $studly;
    }

    /** Prefijo de clase N2 (ClientPackage, p.ej. AcmeShop), o null si no hay target. */
    protected function corePackageOption(): ?string
    {
        $this->coreResolveTarget();

        return $this->corePackageName === null ? null : $this->coreClientName . $this->corePackageName;
    }

    protected function corePackageBasePath(): ?string
    {
        $this->coreResolveTarget();

        return $this->corePackageName === null
            ? null
            : base_path("packages/{$this->coreClientName}/{$this->corePackageName}");
    }

    /** Path RELATIVO del paquete anidado (packages/Acme/Shop), o null. Para opciones tipo --path. */
    protected function corePackageRelativeBasePath(): ?string
    {
        $this->coreResolveTarget();

        return $this->corePackageName === null
            ? null
            : "packages/{$this->coreClientName}/{$this->corePackageName}";
    }

    protected function corePackageRootNamespace(): ?string
    {
        $this->coreResolveTarget();

        return $this->corePackageName === null
            ? null
            : "Packages\\{$this->coreClientName}\\{$this->corePackageName}\\";
    }

    protected function coreWithPackageOption(array $options): array
    {
        $hasClient = $hasPackage = false;

        foreach ($options as $option) {
            $hasClient = $hasClient || ($option[0] ?? null) === 'client';
            $hasPackage = $hasPackage || ($option[0] ?? null) === 'package';
        }

        if (!$hasClient) {
            $options[] = ['client', null, InputOption::VALUE_REQUIRED, 'Cliente del paquete anidado (e.g. Acme). Requiere --package.'];
        }

        if (!$hasPackage) {
            $options[] = ['package', null, InputOption::VALUE_REQUIRED, 'Paquete dentro del cliente (e.g. Shop). Requiere --client.'];
        }

        return $options;
    }

    protected function corePassPackageToCalledCommand(array $arguments): array
    {
        $this->coreResolveTarget();

        if ($this->corePackageName !== null) {
            $arguments['--client'] = $this->coreClientName;
            $arguments['--package'] = $this->corePackageName;
        }

        return $arguments;
    }

    protected function coreNormalizeGeneratorName(string $name, ?string $type = null): string
    {
        $normalized = str_replace('/', '\\', trim($name));

        if ($normalized === '') {
            return $normalized;
        }

        $package = $this->corePackageOption();

        if ($package === null) {
            return $normalized;
        }

        $parts = array_values(array_filter(explode('\\', $normalized), fn (string $part): bool => $part !== ''));

        if ($parts === []) {
            return $normalized;
        }

        $class = Str::studly((string) array_pop($parts));
        $parts = array_map(fn (string $part): string => Str::studly($part), $parts);

        if (is_string($type) && $type !== '') {
            while (Str::endsWith($class, $type . $type)) {
                $class = (string) substr($class, 0, -strlen($type));
            }

            if (!Str::endsWith($class, $type)) {
                $class .= $type;
            }
        }

        if (!Str::startsWith($class, $package)) {
            $class = $package . $class;
        }

        $parts[] = $class;

        return implode('\\', $parts);
    }

    protected function coreEntityNameFromGeneratorName(string $name, ?string $type = null): string
    {
        $entity = Str::studly(class_basename(str_replace('/', '\\', $name)));
        $package = $this->corePackageOption();

        if ($package !== null && Str::startsWith($entity, $package)) {
            $entity = (string) Str::after($entity, $package);
        }

        if (is_string($type) && $type !== '' && Str::endsWith($entity, $type)) {
            $entity = substr($entity, 0, -strlen($type)) ?: '';
        }

        return $entity !== '' ? $entity : Str::studly(class_basename($name));
    }
}
