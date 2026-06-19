<?php

namespace Packages\Commander\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Packages\Commander\Console\Commands\CommanderRefreshCommand;
use Packages\Commander\Console\Commands\CommanderQuotaMeasureCommand;
use Packages\Commander\Console\Commands\CommanderStatusCommand;
use Packages\Commander\Console\Commands\CommanderUsageRollupCommand;

class CommanderServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CommanderRefreshCommand::class,
                CommanderStatusCommand::class,
                CommanderUsageRollupCommand::class,
                CommanderQuotaMeasureCommand::class,
            ]);

            // KPIs del dashboard + snapshots de uso frescos (cron corre schedule:run).
            $this->app->booted(function (): void {
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('commander:refresh')->everyTenMinutes()->withoutOverlapping();
                $schedule->command('commander:usage:rollup --prune')->hourly()->withoutOverlapping();
                $schedule->command('commander:quota:measure')->everySixHours()->withoutOverlapping();
            });
        }
    }
}
