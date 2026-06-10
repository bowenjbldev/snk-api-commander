<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\RuleMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreRuleMakeCommand extends RuleMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
