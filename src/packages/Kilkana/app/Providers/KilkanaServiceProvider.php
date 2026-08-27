<?php

namespace Packages\Kilkana\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Packages\Core\Support\CoreActiveInstanceSupport;
use Packages\Kilkana\Console\Commands\SnkKilkanaEnrichMediaCommand;
use Packages\Kilkana\Console\Commands\SnkKilkanaPublishScheduledCommand;
use Packages\Kilkana\Console\Commands\SnkKilkanaSyncPermissionsCommand;
use Packages\Kilkana\Console\Commands\SnkKilkanaSyncThemesCommand;

class KilkanaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        foreach (['kilkana-view' => 60, 'kilkana-subscribe' => 10, 'kilkana-signed' => 20] as $name => $limit) {
            RateLimiter::for($name, function (Request $request) use ($limit) {
                $tenant = $this->app->make(CoreActiveInstanceSupport::class)->id() ?? 'unknown';

                return Limit::perMinute($limit)->by("{$tenant}:{$request->ip()}");
            });
        }

        // Las vistas del paquete son solo cuerpos de correo. El namespace evita que `kilkana::`
        // compita con las vistas de la aplicación anfitriona.
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'kilkana');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SnkKilkanaPublishScheduledCommand::class,
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
            });
        }
    }
}
