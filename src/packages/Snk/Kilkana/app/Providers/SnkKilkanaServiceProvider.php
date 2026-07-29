<?php

namespace Packages\Snk\Kilkana\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Packages\Snk\Kilkana\Console\Commands\SnkKilkanaEnrichMediaCommand;
use Packages\Snk\Kilkana\Console\Commands\SnkKilkanaPublishScheduledCommand;

class SnkKilkanaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Las vistas del paquete son SOLO cuerpos de correo: la salida pública la pinta la app
        // de Svelte, y el panel también. Namespace propio para que `kilkana::` no compita con
        // las vistas de la app anfitriona.
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'kilkana');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SnkKilkanaPublishScheduledCommand::class,
                SnkKilkanaEnrichMediaCommand::class,
            ]);

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
