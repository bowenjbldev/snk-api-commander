<?php

namespace Packages\Snk\Kilkana\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Packages\Snk\Kilkana\Console\Commands\SnkKilkanaPublishScheduledCommand;

class SnkKilkanaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SnkKilkanaPublishScheduledCommand::class]);

            // Scheduler editorial (cron corre schedule:run, como los del Commander).
            $this->app->booted(function (): void {
                $this->app->make(Schedule::class)
                    ->command('kilkana:publish-scheduled')
                    ->everyMinute()
                    ->withoutOverlapping();
            });
        }
    }
}

