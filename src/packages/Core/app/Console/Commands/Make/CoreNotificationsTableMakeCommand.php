<?php

namespace Packages\Core\Console\Commands\Make;

use Illuminate\Notifications\Console\NotificationTableCommand;
use Packages\Core\Console\Concerns\CorePackageAwareMigrationGeneratorSupport;

class CoreNotificationsTableMakeCommand extends NotificationTableCommand
{
    use CorePackageAwareMigrationGeneratorSupport;
}
