<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\ListenerMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreListenerMakeCommand extends ListenerMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
