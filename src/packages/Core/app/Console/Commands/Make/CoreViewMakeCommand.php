<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\ViewMakeCommand;
use Packages\Core\Console\Concerns\CoreInteractsWithPackageOptionSupport;

class CoreViewMakeCommand extends ViewMakeCommand
{
    use CoreInteractsWithPackageOptionSupport;

    protected function getOptions(): array
    {
        return $this->coreWithPackageOption(parent::getOptions());
    }

    protected function getPath($name)
    {
        $packageBasePath = $this->corePackageBasePath();

        if ($packageBasePath === null) {
            return parent::getPath($name);
        }

        $views = $packageBasePath . '/resources/views';

        return $views . '/' . $this->getNameInput() . '.' . $this->option('extension');
    }
}
