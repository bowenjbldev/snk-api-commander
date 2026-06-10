<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\ClassMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreClassMakeCommand extends ClassMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
