<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Routing\Console\MiddlewareMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreMiddlewareMakeCommand extends MiddlewareMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
