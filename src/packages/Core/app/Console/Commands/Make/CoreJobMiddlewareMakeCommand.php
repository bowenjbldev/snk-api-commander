<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\JobMiddlewareMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreJobMiddlewareMakeCommand extends JobMiddlewareMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
