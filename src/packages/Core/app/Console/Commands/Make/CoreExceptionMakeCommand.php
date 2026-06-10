<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\ExceptionMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreExceptionMakeCommand extends ExceptionMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
