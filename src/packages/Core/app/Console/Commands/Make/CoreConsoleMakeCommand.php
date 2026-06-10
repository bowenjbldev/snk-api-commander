<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\ConsoleMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreConsoleMakeCommand extends ConsoleMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
