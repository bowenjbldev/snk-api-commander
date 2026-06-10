<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\EventMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreEventMakeCommand extends EventMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
