<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\PolicyMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CorePolicyMakeCommand extends PolicyMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
