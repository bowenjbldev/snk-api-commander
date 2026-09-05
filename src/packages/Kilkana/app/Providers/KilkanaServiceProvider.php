<?php

namespace Packages\Kilkana\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Packages\Core\Support\CoreActiveInstanceSupport;
use Packages\Kilkana\Console\Commands\SnkKilkanaEnrichMediaCommand;
use Packages\Kilkana\Console\Commands\SnkKilkanaNewsletterSendDueCommand;
use Packages\Kilkana\Console\Commands\SnkKilkanaPublishScheduledCommand;
use Packages\Kilkana\Console\Commands\SnkKilkanaPurgeTrashCommand;
use Packages\Kilkana\Console\Commands\SnkKilkanaPurgeVariantsCommand;
use Packages\Kilkana\Console\Commands\SnkKilkanaSyncPermissionsCommand;
use Packages\Kilkana\Console\Commands\SnkKilkanaSyncThemesCommand;
use Packages\Kilkana\Services\SnkKilkanaActivityService;

class KilkanaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Una sola instancia: `quiet()` es un estado, y con una instancia por llamada el que
        // calla no es el que escucha a los modelos.
        $this->app->singleton(SnkKilkanaActivityService::class);
    }

    public function boot(): void
    {
        foreach (['kilkana-view' => 60, 'kilkana-subscribe' => 10, 'kilkana-signed' => 20] as $name => $limit) {
            RateLimiter::for($name, function (Request $request) use ($limit) {
                $tenant = $this->app->make(CoreActiveInstanceSupport::class)->id() ?? 'unknown';

                return Limit::perMinute($limit)->by("{$tenant}:{$request->ip()}");
            });
        }

        // El registro de actividad escucha a los modelos desde aquí: un solo enganche, para que
        // ninguna escritura del panel pase sin dejar rastro.
        $this->app->make(SnkKilkanaActivityService::class)->observe();

        // Las vistas del paquete son solo cuerpos de correo. El namespace evita que `kilkana::`
        // compita con las vistas de la aplicación anfitriona.
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'kilkana');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SnkKilkanaPublishScheduledCommand::class,
                SnkKilkanaNewsletterSendDueCommand::class,
                SnkKilkanaPurgeTrashCommand::class,
                SnkKilkanaPurgeVariantsCommand::class,
                SnkKilkanaEnrichMediaCommand::class,
                SnkKilkanaSyncPermissionsCommand::class,
                SnkKilkanaSyncThemesCommand::class,
            ]);

            // Scheduler editorial (cron corre schedule:run, como los del Commander).
            $this->app->booted(function (): void {
                $this->app->make(Schedule::class)
                    ->command('kilkana:publish-scheduled')
                    ->everyMinute()
                    ->withoutOverlapping();
                // Los números del boletín con hora: al minuto, como las piezas programadas.
                $this->app->make(Schedule::class)
                    ->command('kilkana:newsletter-send-due')
                    ->everyMinute()
                    ->withoutOverlapping();
                // Las derivadas huérfanas: de madrugada, después de la papelera.
                $this->app->make(Schedule::class)
                    ->command('kilkana:purge-variants')
                    ->dailyAt('04:40')
                    ->withoutOverlapping();
                // La cuenta atrás de la papelera: de madrugada, cuando nadie restaura.
                $this->app->make(Schedule::class)
                    ->command('kilkana:purge-trash')
                    ->dailyAt('04:10')
                    ->withoutOverlapping();
            });
        }
    }
}
