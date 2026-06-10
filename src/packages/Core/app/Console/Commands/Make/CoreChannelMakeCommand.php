<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\ChannelMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreChannelMakeCommand extends ChannelMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
