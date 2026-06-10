<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Database\Console\Factories\FactoryMakeCommand;
use Illuminate\Support\Stringable;
use Packages\Core\Console\Concerns\CoreInteractsWithPackageOptionSupport;

class CoreFactoryMakeCommand extends FactoryMakeCommand
{
    use CoreInteractsWithPackageOptionSupport;

    protected function getOptions()
    {
        return $this->coreWithPackageOption(parent::getOptions());
    }

    protected function rootNamespace()
    {
        return $this->corePackageRootNamespace() ?? parent::rootNamespace();
    }

    protected function getPath($name)
    {
        $packageBasePath = $this->corePackageBasePath();

        if ($packageBasePath === null) {
            return parent::getPath($name);
        }

        $name = (new Stringable($name))
            ->replaceFirst($this->rootNamespace(), '')
            ->finish('Factory')
            ->value();

        return $packageBasePath . '/database/factories/' . str_replace('\\', '/', $name) . '.php';
    }
}
