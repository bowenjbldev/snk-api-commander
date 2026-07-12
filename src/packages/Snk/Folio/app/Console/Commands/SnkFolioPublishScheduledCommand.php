<?php

namespace Packages\Snk\Folio\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Snk\Folio\Models\SnkFolioPostModel;

/**
 * Scheduler editorial: publica los posts `scheduled` cuya fecha venció, en CADA
 * instancia provisionada de Folio (mismo patrón de conmutación de conexión que
 * CoreResolvePackageFromHostMiddleware, pero iterando el registro del Commander).
 * Corre por cron vía schedule:run (registrado en SnkFolioServiceProvider).
 */
class SnkFolioPublishScheduledCommand extends Command
{
    protected $signature = 'folio:publish-scheduled';

    protected $description = 'Publish scheduled Folio posts whose time has come, across all provisioned tenants';

    public function handle(): int
    {
        // El registro vive en la BD commander (conexión propia del modelo):
        // funciona se conmute lo que se conmute después.
        $instances = CommanderPackageModel::query()
            ->provisioned()
            ->where('slug', 'folio')
            ->whereNotNull('database')
            ->get(['id', 'database']);

        $default = DB::getDefaultConnection();
        $total = 0;

        foreach ($instances as $instance) {
            config(['database.connections.tenant.database' => $instance->database]);
            DB::purge('tenant');
            DB::setDefaultConnection('tenant');

            // published_at = la fecha programada (no now): el orden editorial es el previsto.
            $due = SnkFolioPostModel::query()
                ->where('status', 'scheduled')
                ->whereNotNull('scheduled_for')
                ->where('scheduled_for', '<=', now())
                ->get();
            foreach ($due as $post) {
                $post->update([
                    'status' => 'published',
                    'published_at' => $post->published_at ?? $post->scheduled_for,
                    'scheduled_for' => null,
                ]);
            }

            if ($due->isNotEmpty()) {
                $this->info("[{$instance->database}] published {$due->count()} post(s)");
            }
            $total += $due->count();
        }

        DB::setDefaultConnection($default);
        $this->info("Done: {$total} post(s) published across {$instances->count()} tenant(s).");

        return self::SUCCESS;
    }
}
