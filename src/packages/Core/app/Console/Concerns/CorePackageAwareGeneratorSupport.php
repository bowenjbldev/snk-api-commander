<?php

namespace Packages\Core\Console\Concerns;

use Illuminate\Support\Str;

trait CorePackageAwareGeneratorSupport
{
    use CoreInteractsWithPackageOptionSupport;

    protected function getOptions(): array
    {
        return $this->coreWithPackageOption(parent::getOptions());
    }

    protected function rootNamespace()
    {
        return $this->corePackageRootNamespace() ?? parent::rootNamespace();
    }

    protected function getNameInput()
    {
        $name = (string) parent::getNameInput();
        $type = property_exists($this, 'type') && is_string($this->type) ? trim($this->type) : null;

        return $this->coreNormalizeGeneratorName($name, $type !== '' ? $type : null);
    }

    protected function getPath($name)
    {
        $packageBasePath = $this->corePackageBasePath();

        if ($packageBasePath === null) {
            return parent::getPath($name);
        }

        $relative = Str::replaceFirst($this->rootNamespace(), '', $name);

        return $packageBasePath . '/app/' . str_replace('\\', '/', $relative) . '.php';
    }

    protected function viewPath($path = '')
    {
        $packageBasePath = $this->corePackageBasePath();

        if ($packageBasePath === null) {
            return parent::viewPath($path);
        }

        $views = $packageBasePath . '/resources/views';

        return $views . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }
}
