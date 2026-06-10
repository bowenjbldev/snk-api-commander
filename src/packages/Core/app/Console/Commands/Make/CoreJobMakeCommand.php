<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\JobMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreJobMakeCommand extends JobMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
