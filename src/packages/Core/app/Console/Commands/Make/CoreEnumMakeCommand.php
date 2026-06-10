<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\EnumMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreEnumMakeCommand extends EnumMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
