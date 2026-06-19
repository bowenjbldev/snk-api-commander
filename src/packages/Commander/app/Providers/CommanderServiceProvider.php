<?php

namespace Packages\Commander\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Packages\Commander\Console\Commands\CommanderRefreshCommand;
use Packages\Commander\Console\Commands\CommanderQuotaMeasureCommand;
use Packages\Commander\Console\Commands\CommanderStatusCommand;
use Packages\Commander\Console\Commands\CommanderUsageRollupCommand;
use Packages\Core\Support\CorePackageSelectorSupport;

class CommanderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Solo cuando el Commander es el package ÚNICO del deploy (API_PACKAGE=Commander, panel
        // del operador aislado) apaga el commander de Core (metering/kill-switch/cuotas). En
        // modo centralizado (API_PACKAGE vacio) el Commander es un package mas y NO debe apagar el
        // commander del resto de tenants. Bajo tests se mantiene ON (la suite ejercita commander_*).
        $locked = CorePackageSelectorSupport::activePackageName();

        if (! $this->app->runningUnitTests()
            && $locked !== null
            && strcasecmp($locked, 'Commander') === 0) {
            config(['core.commander' => false]);
        }
    }

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
