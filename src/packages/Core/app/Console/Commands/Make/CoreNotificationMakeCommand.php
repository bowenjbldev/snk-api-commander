<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Foundation\Console\NotificationMakeCommand;
use Packages\Core\Console\Concerns\CorePackageAwareGeneratorSupport;

class CoreNotificationMakeCommand extends NotificationMakeCommand
{
    use CorePackageAwareGeneratorSupport;
}
