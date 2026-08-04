<?php

namespace Packages\Kilkana\Console\Commands;

use Illuminate\Console\Command;
use Packages\Kilkana\Services\SnkKilkanaTenantRunnerService;
use Packages\Kilkana\Services\SnkKilkanaThemeCatalogSyncService;

/**
 * Publica el catálogo de temas en cada periódico aprovisionado.
 *
 * El catálogo vive replicado —una copia por base— porque cada periódico tiene la suya y entre
 * bases no hay claves foráneas. Este comando es lo que lo distribuye, y se ejecuta tras un
 * despliegue que traiga temas nuevos.
 *
 * Sin opciones, igual que `kilkana:enrich-media`: dónde corre lo decide
 * `SnkKilkanaTenantRunnerService`, y qué se escribe, el servicio de sincronización. Un tema que
 * no valida no entra, y el comando termina en fallo — publicar a medias un catálogo es peor
 * que no publicarlo.
 */
class SnkKilkanaSyncThemesCommand extends Command
{
    protected $signature = 'kilkana:sync-themes';

    protected $description = 'Publish the theme catalog from the master JSON files into every tenant';

    public function __construct(
        private readonly SnkKilkanaTenantRunnerService $tenants,
        private readonly SnkKilkanaThemeCatalogSyncService $catalog,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $run = $this->tenants->each(fn () => $this->catalog->sync());

        foreach ($run['errors'] as $database => $message) {
            $this->error("[{$database}] {$message}");
        }

        $escritos = 0;
        $saltados = 0;
        $problemas = 0;

        foreach ($run['results'] as $database => $resumen) {
            $escritos += $resumen['written'];
            $saltados += $resumen['skipped'];

            foreach ($resumen['errors'] as $error) {
                $problemas++;
                $this->error("[{$database}] {$error}");
            }
        }

        $fallos = count($run['errors']) + $problemas;

        $this->info(
            "Done: {$escritos} theme(s) written, {$saltados} unchanged across {$run['tenants']} tenant(s); {$fallos} problem(s)."
        );

        return $fallos === 0 ? self::SUCCESS : self::FAILURE;
    }
}
