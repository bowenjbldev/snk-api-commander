<?php

namespace Packages\Commander\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Packages\Commander\Console\Commands\CommanderRefreshCommand;
use Packages\Core\Support\CorePackageSelectorSupport;

class CommanderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Solo cuando el Commander es el package ÚNICO del deploy (API_PACKAGE=Commander, panel
        // del operador aislado) apaga el control plane de Core (metering/kill-switch/cuotas). En
        // modo centralizado (API_PACKAGE vacio) el Commander es un package mas y NO debe apagar el
        // control plane del resto de tenants. Bajo tests se mantiene ON (la suite ejercita core_*).
        $locked = CorePackageSelectorSupport::activePackageName();

        if (! $this->app->runningUnitTests()
            && $locked !== null
            && strcasecmp($locked, 'Commander') === 0) {
            config(['core.control_plane' => false]);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CommanderRefreshCommand::class]);

            // Mantiene los KPIs del dashboard frescos (cron corre schedule:run).
            $this->app->booted(function (): void {
                $this->app->make(Schedule::class)
                    ->command('commander:refresh')
                    ->everyTenMinutes()
                    ->withoutOverlapping();
            });
        }
    }
}
