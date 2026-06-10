<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\TestMakeCommand;
use Illuminate\Support\Str;
use Packages\Core\Console\Concerns\CoreInteractsWithPackageOptionSupport;

class CoreTestMakeCommand extends TestMakeCommand
{
    use CoreInteractsWithPackageOptionSupport;

    protected function getOptions()
    {
        return $this->coreWithPackageOption(parent::getOptions());
    }

    protected function rootNamespace()
    {
        $package = $this->corePackageOption();

        return $package === null
            ? parent::rootNamespace()
            : "Packages\\{$package}\\Tests";
    }

    protected function getPath($name)
    {
        $packageBasePath = $this->corePackageBasePath();

        if ($packageBasePath === null) {
            return parent::getPath($name);
        }

        $relative = Str::replaceFirst($this->rootNamespace(), '', $name);

        return $packageBasePath . '/tests' . str_replace('\\', '/', $relative) . '.php';
    }
}
