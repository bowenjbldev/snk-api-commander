<?php

namespace Packages\Snk\Folio\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Packages\Snk\Folio\Console\Commands\SnkFolioPublishScheduledCommand;

class SnkFolioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SnkFolioPublishScheduledCommand::class]);

            // Scheduler editorial (cron corre schedule:run, como los del Commander).
            $this->app->booted(function (): void {
                $this->app->make(Schedule::class)
                    ->command('folio:publish-scheduled')
                    ->everyMinute()
                    ->withoutOverlapping();
            });
        }
    }
}

