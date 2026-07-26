<?php

namespace Packages\Snk\Kilkana\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Snk\Kilkana\Enums\SnkKilkanaPostStatusEnum;
use Packages\Snk\Kilkana\Models\SnkKilkanaPostModel;

/**
 * Scheduler editorial: publica los posts `scheduled` cuya fecha venció, en CADA
 * instancia provisionada de Kilkana (mismo patrón de conmutación de conexión que
 * CoreResolvePackageFromHostMiddleware, pero iterando el registro del Commander).
 * Corre por cron vía schedule:run (registrado en SnkKilkanaServiceProvider).
 */
class SnkKilkanaPublishScheduledCommand extends Command
{
    protected $signature = 'kilkana:publish-scheduled';

    protected $description = 'Publish scheduled Kilkana posts whose time has come, across all provisioned tenants';

    public function handle(): int
    {
        // El registro vive en la BD commander (conexión propia del modelo):
        // funciona se conmute lo que se conmute después.
        $instances = CommanderPackageModel::query()
            ->provisioned()
            ->where('slug', 'kilkana')
            ->whereNotNull('database')
            ->get(['id', 'database', 'database_username', 'database_password']);

        $default = DB::getDefaultConnection();
        $tenantDatabase = config('database.connections.tenant.database');
        $tenantUsername = config('database.connections.tenant.username');
        $tenantPassword = config('database.connections.tenant.password');
        $total = 0;
        $failures = 0;

        try {
            foreach ($instances as $instance) {
                try {
                    config([
                        'database.connections.tenant.database' => $instance->database,
                        'database.connections.tenant.username' => $instance->database_username,
                        'database.connections.tenant.password' => $instance->database_password,
                    ]);
                    DB::purge('tenant');
                    DB::setDefaultConnection('tenant');

                    // Publicar es SOLO cambiar de estado: la fecha ya está puesta y es la
                    // prevista. Cuando la fecha prevista y la de publicación eran dos
                    // columnas, aquí había que copiar una en otra y vaciar la primera, y ese
                    // traspaso llegó a publicar piezas con la fecha en que se redactaron
                    // —descolocando portada, sitemap y feed—. Sin dos columnas no hay
                    // traspaso que equivocar.
                    $due = SnkKilkanaPostModel::query()
                        ->where('status', SnkKilkanaPostStatusEnum::Scheduled)
                        ->whereNotNull('publish_at')
                        ->where('publish_at', '<=', now())
                        ->get();
                    foreach ($due as $post) {
                        $post->update(['status' => SnkKilkanaPostStatusEnum::Published]);
                    }

                    if ($due->isNotEmpty()) {
                        $this->info("[{$instance->database}] published {$due->count()} post(s)");
                    }
                    $total += $due->count();
                } catch (\Throwable $e) {
                    $failures++;
                    report($e);
                    $this->error("[{$instance->database}] {$e->getMessage()}");
                }
            }
        } finally {
            DB::purge('tenant');
            config([
                'database.connections.tenant.database' => $tenantDatabase,
                'database.connections.tenant.username' => $tenantUsername,
                'database.connections.tenant.password' => $tenantPassword,
            ]);
            DB::setDefaultConnection($default);
        }

        $this->info("Done: {$total} post(s) published across {$instances->count()} tenant(s); {$failures} failed.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
