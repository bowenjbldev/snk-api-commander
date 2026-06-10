<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\ObserverMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreObserverMakeCommand extends ObserverMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
