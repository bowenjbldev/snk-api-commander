<?php

namespace Packages\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Packages\Core\Console\Commands\CoreMakePackageCommand;
use Packages\Core\Console\Commands\CoreMigrateCommand;
use Packages\Core\Console\Commands\CoreMigrateRefreshCommand;
use Packages\Core\Console\Commands\CoreRemovePackageCommand;
use Packages\Core\Console\Commands\CoreSeedCommand;

class CoreConsoleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Seeder binding + comandos operacionales: también deben existir en HTTP, porque el
        // provisioning del Commander llama core:migrate / core:seed vía Artisan::call durante
        // un request. $this->commands() difiere el registro al arrancar Artisan (on-demand en HTTP).
        $this->app->bind(\Database\Seeders\DatabaseSeeder::class, function ($app) {
            return $app->make(\Packages\Core\Database\Seeders\CorePackagesDatabaseSeeder::class);
        });

        $this->commands([
            CoreMigrateCommand::class,
            CoreMigrateRefreshCommand::class,
            CoreSeedCommand::class,
        ]);

        // Generadores make:* y scaffolding: solo en consola (no se usan desde HTTP).
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->app->singleton(\Packages\Core\Console\Commands\Make\CoreMigrationMakeCommand::class, function ($app) {
            return new \Packages\Core\Console\Commands\Make\CoreMigrationMakeCommand(
                $app['migration.creator'],
                $app['composer']
            );
        });

        $this->commands([
            CoreMakePackageCommand::class,
            CoreRemovePackageCommand::class,
            \Packages\Core\Console\Commands\Make\CoreCacheTableMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreCastMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreChannelMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreClassMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreComponentMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreConfigMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreConsoleMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreControllerMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreEnumMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreEventMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreExceptionMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreFactoryMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreInterfaceMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreJobMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreJobMiddlewareMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreListenerMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreMailMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreMiddlewareMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreMigrationMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreModelMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreNotificationMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreNotificationsTableMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreObserverMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CorePolicyMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreProviderMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreQueueBatchesTableMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreQueueFailedTableMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreQueueTableMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreRequestMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreResourceMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreRuleMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreScopeMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreSeederMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreSessionTableMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreTestMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreTraitMakeCommand::class,
            \Packages\Core\Console\Commands\Make\CoreViewMakeCommand::class,
        ]);
    }
}
