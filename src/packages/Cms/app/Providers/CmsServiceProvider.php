<?php

namespace Packages\Cms\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Packages\Cms\Console\Commands\CmsDeregisterDeployCommand;
use Packages\Cms\Console\Commands\CmsRegisterDeployCommand;
use Packages\Cms\Console\Commands\CmsSyncDeploysCommand;

class CmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CmsRegisterDeployCommand::class,
                CmsDeregisterDeployCommand::class,
                CmsSyncDeploysCommand::class,
            ]);

            // Keep the local mirror + dashboard fresh without manual syncs (cron runs schedule:run).
            $this->app->booted(function (): void {
                $this->app->make(Schedule::class)
                    ->command('cms:sync-deploys')
                    ->hourly()
                    ->withoutOverlapping();
            });
        }
    }
}

