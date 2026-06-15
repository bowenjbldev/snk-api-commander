<?php

namespace Packages\Commander\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Packages\Commander\Console\Commands\CommanderDeregisterDeployCommand;
use Packages\Commander\Console\Commands\CommanderRegisterDeployCommand;
use Packages\Commander\Console\Commands\CommanderSyncDeploysCommand;

class CommanderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CommanderRegisterDeployCommand::class,
                CommanderDeregisterDeployCommand::class,
                CommanderSyncDeploysCommand::class,
            ]);

            // Keep the local mirror + dashboard fresh without manual syncs (cron runs schedule:run).
            $this->app->booted(function (): void {
                $this->app->make(Schedule::class)
                    ->command('commander:sync-deploys')
                    ->hourly()
                    ->withoutOverlapping();
            });
        }
    }
}

