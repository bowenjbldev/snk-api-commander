<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\ResourceMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreResourceMakeCommand extends ResourceMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
