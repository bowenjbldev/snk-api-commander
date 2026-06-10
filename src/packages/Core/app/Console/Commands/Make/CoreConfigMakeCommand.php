<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\ConfigMakeCommand;
use Illuminate\Support\Str;
use Packages\Core\Console\Concerns\CoreInteractsWithPackageOptionSupport;

class CoreConfigMakeCommand extends ConfigMakeCommand
{
    use CoreInteractsWithPackageOptionSupport;

    protected function getOptions(): array
    {
        return $this->coreWithPackageOption(parent::getOptions());
    }

    protected function getPath($name): string
    {
        $packageBasePath = $this->corePackageBasePath();

        if ($packageBasePath === null) {
            return parent::getPath($name);
        }

        return $packageBasePath . '/config/' . Str::finish($this->argument('name'), '.php');
    }
}
