<?php

namespace Packages\Commander\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Packages\Commander\Console\Commands\CommanderDeregisterDeployCommand;
use Packages\Commander\Console\Commands\CommanderProvisionEnvCommand;
use Packages\Commander\Console\Commands\CommanderRegisterDeployCommand;
use Packages\Commander\Console\Commands\CommanderSyncDeploysCommand;

class CommanderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The Commander is the operator panel, not a managed tenant: turn off Core's control plane
        // (metering, kill-switch, quotas and the /control surface) for this deploy. Declared here in
        // the package — NOT by Core sniffing API_PACKAGE — so Core stays agnostic of business-package
        // names. register() runs before CoreRouteServiceProvider::boot() reads the flag, so it sees it.
        // Kept ON under tests: the suite runs as the Commander and must exercise Core's control plane
        // machinery (signing, rotation, the core_* tables), which the gate would otherwise strip.
        if (! $this->app->runningUnitTests()) {
            config(['core.control_plane' => false]);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CommanderRegisterDeployCommand::class,
                CommanderDeregisterDeployCommand::class,
                CommanderSyncDeploysCommand::class,
                CommanderProvisionEnvCommand::class,
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

