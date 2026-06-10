<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\TraitMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreTraitMakeCommand extends TraitMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
