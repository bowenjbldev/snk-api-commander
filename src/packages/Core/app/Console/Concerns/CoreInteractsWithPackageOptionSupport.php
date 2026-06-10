<?php

namespace Packages\Core\Console\Concerns;

use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

trait CoreInteractsWithPackageOptionSupport
{
    private bool $corePackageOptionResolved = false;
    private ?string $coreResolvedPackageName = null;

    protected function corePackageOption(): ?string
    {
        if ($this->corePackageOptionResolved) {
            return $this->coreResolvedPackageName;
        }

        $this->corePackageOptionResolved = true;
        $raw = $this->option('package');

        if (!is_string($raw) || trim($raw) === '') {
            return $this->coreResolvedPackageName = null;
        }

        $normalized = preg_replace('/[^A-Za-z0-9]+/', ' ', trim($raw)) ?? '';
        $package = Str::studly($normalized);

        if ($package === '' || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $package)) {
            $this->fail('Invalid package name provided in --package.');
        }

        $path = base_path("packages/{$package}");

        if (!is_dir($path)) {
            $this->fail("Package [{$package}] does not exist in {$path}.");
        }

        return $this->coreResolvedPackageName = $package;
    }

    protected function corePackageBasePath(): ?string
    {
        $package = $this->corePackageOption();

        return $package === null ? null : base_path("packages/{$package}");
    }

    protected function corePackageRootNamespace(): ?string
    {
        $package = $this->corePackageOption();

        return $package === null ? null : "Packages\\{$package}\\";
    }

    protected function coreWithPackageOption(array $options): array
    {
        foreach ($options as $option) {
            if (($option[0] ?? null) === 'package') {
                return $options;
            }
        }

        $options[] = ['package', null, InputOption::VALUE_REQUIRED, 'Target package (e.g. Rebooking)'];

        return $options;
    }

    protected function corePassPackageToCalledCommand(array $arguments): array
    {
        $package = $this->corePackageOption();

        if ($package !== null) {
            $arguments['--package'] = $package;
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
