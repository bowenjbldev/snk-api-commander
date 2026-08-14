<?php

namespace Packages\Commander\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Packages\Commander\Console\Commands\CommanderApplicationsSyncCommand;
use Packages\Commander\Console\Commands\CommanderGitSyncCommand;
use Packages\Commander\Console\Commands\CommanderQuotaMeasureCommand;
use Packages\Commander\Console\Commands\CommanderRefreshCommand;
use Packages\Commander\Console\Commands\CommanderSecureTenantsCommand;
use Packages\Commander\Console\Commands\CommanderStatusCommand;
use Packages\Commander\Console\Commands\CommanderUsageRollupCommand;
use Packages\Commander\Contracts\CommanderPackageGitSource;
use Packages\Commander\Git\CommanderLocalGitSource;

class CommanderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Origen del historial git: CommanderLocalGitSource (lee el .git montado :ro). Swappable por
        // CommanderRemoteGitSource (API del proveedor git) cuando el host no tenga .git.
        $this->app->bind(CommanderPackageGitSource::class, fn () => new CommanderLocalGitSource(
            (string) config('commander.git.git_dir', '/var/www/.git'),
            (string) config('commander.git.work_tree', '/var/www'),
            (string) config('commander.git.binary', 'git'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CommanderApplicationsSyncCommand::class,
                CommanderRefreshCommand::class,
                CommanderStatusCommand::class,
                CommanderUsageRollupCommand::class,
                CommanderQuotaMeasureCommand::class,
                CommanderGitSyncCommand::class,
                CommanderSecureTenantsCommand::class,
            ]);

            // KPIs del dashboard + snapshots de uso frescos (cron corre schedule:run).
            $this->app->booted(function (): void {
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('commander:refresh')->everyTenMinutes()->withoutOverlapping();
                $schedule->command('commander:usage:rollup --prune')->hourly()->withoutOverlapping();
                $schedule->command('commander:quota:measure')->everySixHours()->withoutOverlapping();
                $schedule->command('commander:git:sync')->hourly()->withoutOverlapping();
            });
        }
    }
}
