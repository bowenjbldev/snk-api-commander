<?php

namespace Packages\Kilkana\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Packages\Core\Support\CorePackageSelectorSupport;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Packages\Kilkana\Services\SnkKilkanaTenantRunnerService;

/**
 * Reaplica el reparto de roles en cada periódico aprovisionado.
 *
 * **Un despliegue no vuelve a sembrar.** `core:migrate --all-tenants` es lo que corre sobre
 * todos —y rechaza `--seed` por diseño—, así que un permiso nuevo nace en el seeder y no llega
 * a ningún periódico que ya existiera: los que se dieron de alta después lo tienen y los de
 * antes no, sin que falle nada. `view others posts` estuvo así en tres de las cuatro bases
 * locales, y la avería que produce es peor que una caída — parcial y verosímil: los roles que
 * pueden editar lo ajeno siguen viendo el sumario porque no les hace falta el permiso, y los
 * que no, lo pierden en silencio.
 *
 * Es un comando y no una migración a propósito. El reparto lo escribe
 * {@see SnkKilkanaRoleCatalogService} y **solo él**; una migración que repartiera permisos
 * sería un segundo escritor de `role_has_permissions`, y el día que las dos listas
 * discreparan ganaría la que se ejecutó por última vez. Misma forma que
 * `kilkana:sync-themes`, y por el mismo motivo: hay estado replicado por base que un
 * despliegue tiene que volver a publicar.
 */
class SnkKilkanaSyncPermissionsCommand extends Command
{
    protected $signature = 'kilkana:sync-permissions';

    protected $description = 'Reapply the newsroom role catalog into every provisioned tenant';

    public function __construct(
        private readonly SnkKilkanaTenantRunnerService $tenants,
        private readonly SnkKilkanaRoleCatalogService $catalog,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $run = $this->tenants->each(function (string $database) {
            $resumen = $this->catalog->sync();

            // La caché de permisos de Spatie está en `array` en consola —por proceso, y se
            // tira al salir—, así que olvidarla aquí no le dice nada a los FPM vivos: ellos
            // leen la persistente, con la clave del inquilino que les pone
            // `CoreResolveInstanceFromHostMiddleware`. Es esa entrada la que hay que soltar, o
            // el periódico sigue repartiendo el reparto viejo hasta que caduque.
            Cache::forget(CorePackageSelectorSupport::permissionCacheKey($database));

            return $resumen;
        });

        foreach ($run['errors'] as $database => $message) {
            $this->error("[{$database}] {$message}");
        }

        $this->info(sprintf(
            'Done: role catalog applied to %d of %d tenant(s); %d problem(s).',
            count($run['results']),
            $run['tenants'],
            count($run['errors'])
        ));

        return $run['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
