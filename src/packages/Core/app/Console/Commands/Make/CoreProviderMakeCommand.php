<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\ProviderMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreProviderMakeCommand extends ProviderMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
