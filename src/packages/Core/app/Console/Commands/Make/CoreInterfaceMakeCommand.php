<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\InterfaceMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreInterfaceMakeCommand extends InterfaceMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
