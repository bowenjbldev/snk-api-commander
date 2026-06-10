<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Database\Console\Seeds\SeederMakeCommand;
use Illuminate\Support\Str;
use Packages\Core\Console\Concerns\CoreInteractsWithPackageOptionSupport;

class CoreSeederMakeCommand extends SeederMakeCommand
{
    use CoreInteractsWithPackageOptionSupport;

    protected function getOptions(): array
    {
        return $this->coreWithPackageOption(parent::getOptions());
    }

    protected function rootNamespace()
    {
        $package = $this->corePackageOption();

        return $package === null
            ? parent::rootNamespace()
            : "Packages\\{$package}\\Database\\Seeders\\";
    }

    protected function getPath($name)
    {
        $packageBasePath = $this->corePackageBasePath();

        if ($packageBasePath === null) {
            return parent::getPath($name);
        }

        $relative = str_replace('\\', '/', Str::replaceFirst($this->rootNamespace(), '', $name));

        return $packageBasePath . '/database/seeders/' . $relative . '.php';
    }
}
