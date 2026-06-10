<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\ScopeMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreScopeMakeCommand extends ScopeMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
