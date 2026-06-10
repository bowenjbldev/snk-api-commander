<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\ComponentMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreComponentMakeCommand extends ComponentMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
