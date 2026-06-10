<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\RequestMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreRequestMakeCommand extends RequestMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
