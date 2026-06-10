<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\MailMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreMailMakeCommand extends MailMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
