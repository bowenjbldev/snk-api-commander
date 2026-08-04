<?php

namespace Packages\Kilkana\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Packages\Core\Support\CoreActivePackageSupport;
use Packages\Kilkana\Console\Commands\SnkKilkanaEnrichMediaCommand;
use Packages\Kilkana\Console\Commands\SnkKilkanaPublishScheduledCommand;
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
                $tenant = $this->app->make(CoreActivePackageSupport::class)->id() ?? 'unknown';

                return Limit::perMinute($limit)->by("{$tenant}:{$request->ip()}");
            });
        }

        // Las vistas del paquete son SOLO cuerpos de correo: la salida pública la pinta la app
        // de Svelte, y el panel también. Namespace propio para que `kilkana::` no compita con
        // las vistas de la app anfitriona.
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'kilkana');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SnkKilkanaPublishScheduledCommand::class,
                SnkKilkanaEnrichMediaCommand::class,
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
