<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\CastMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreCastMakeCommand extends CastMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
