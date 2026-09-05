<?php

namespace Packages\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Core\Support\CoreActiveInstanceSupport;
use Packages\Core\Support\CorePackageSelectorSupport;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class CoreResolveInstanceFromHostMiddleware
{
    public function __construct(private readonly CoreActiveInstanceSupport $active) {}

    public function handle(Request $request, Closure $next, string $slug): Response
    {
        $clientSubdomain = CorePackageSelectorSupport::subdomainFromHost($request->getHost());
        $instance = $clientSubdomain !== null
            ? CommanderInstanceModel::query()
                ->ready()
                ->whereHas('client', fn ($query) => $query
                    ->where('slug', $clientSubdomain)
                    ->where('status', 'active'))
                ->whereHas('application', fn ($query) => $query
                    ->where('slug', $slug)
                    ->where('status', 'active')
                    ->where('code_status', 'available'))
                ->first()
            : null;

        if ($instance === null) {
            abort(404);
        }

        if (empty($instance->database) || empty($instance->database_username) || empty($instance->database_password)) {
            abort(503, 'Tenant database is not available.');
        }

        try {
            CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database);
            CorePackageSelectorSupport::validatedDatabaseIdentifier($instance->database_username);
        } catch (\RuntimeException) {
            abort(503, 'Tenant database is not available.');
        }

        $originalConnection = DB::getDefaultConnection();
        $originalTenantConfig = config('database.connections.tenant');
        $originalConfig = [];

        try {
            $this->active->set($instance);
            config([
                'database.connections.tenant.database' => $instance->database,
                'database.connections.tenant.username' => $instance->database_username,
                'database.connections.tenant.password' => $instance->database_password,
            ]);
            DB::purge('tenant');
            DB::setDefaultConnection('tenant');
            $this->scopePermissionCache($instance->database);

            $packageConfigKey = strtolower(Str::studly($slug));
            $clientConfigKey = strtolower(Str::studly($clientSubdomain));
            $originalConfig = $this->applyConfigOverrides(
                [$packageConfigKey, "{$clientConfigKey}.{$packageConfigKey}"],
                $packageConfigKey,
            );
            $request->route()?->forgetParameter('client');

            return $next($request);
        } finally {
            foreach ($originalConfig as $key => $value) {
                config([$key => $value]);
            }
            config(['database.connections.tenant' => $originalTenantConfig]);
            DB::setDefaultConnection($originalConnection);
            DB::purge('tenant');
            $this->active->forget();
            $this->scopePermissionCache(null);
        }
    }

    /**
     * La cache de permisos de Spatie, acotada a ESTA base.
     *
     * El registro de permisos cuesta **~19 ms de PHP** por carga —medido: 1,4 ms de SQL y 23,5
     * de construir el arbol de 57 permisos y 7 roles— y `CoreServiceProvider` lo deja en el
     * almacen `array`, que es por peticion: se reconstruye entero en cada request y se tira.
     * Ese `array` no es un descuido, es lo que impide que una cache compartida sirva los
     * permisos de un periodico a otro, y esa regla no se toca.
     *
     * Lo que cambia es que la clave lleve el nombre de la base, con lo que la cache persistente
     * vuelve a ser segura: dos inquilinos no pueden compartir entrada porque no comparten
     * clave. `forgetCachedPermissions()` sigue olvidando solo la del inquilino activo, que es
     * lo correcto cuando alguien edita un rol.
     *
     * **Y se hace aqui y solo aqui, a proposito.** Este es el unico sitio que conmuta la
     * conexion en HTTP —una peticion, un inquilino, un cambio—, asi que la clave no puede
     * quedarse vieja. Los otros ocho sitios que conmutan son CLI y trabajos en cola, donde un
     * mismo proceso recorre VARIOS inquilinos: ahi sigue mandando el `array` del proveedor, que
     * no necesita que nadie se acuerde de nada.
     *
     * Con `null` vuelve al estado de fabrica, para que lo que corra despues de esta peticion
     * en el mismo proceso no herede ni la clave ni los permisos de un inquilino que ya no es el
     * activo.
     */
    private function scopePermissionCache(?string $database): void
    {
        config($database === null
            ? ['permission.cache.store' => 'array', 'permission.cache.key' => 'spatie.permission.cache']
            : [
                'permission.cache.store' => 'default',
                'permission.cache.key' => CorePackageSelectorSupport::permissionCacheKey($database),
            ]);

        // `initializeCache()` es lo que relee las tres claves; sin esto el registrar se queda
        // con el almacen y la clave que resolvio al construirse. La coleccion ya cargada en
        // memoria no la toca, y un proceso que sirve dos inquilinos seguidos —worker
        // persistente, CLI que recorre bases— le serviria al segundo los permisos del primero.
        $registrar = app(PermissionRegistrar::class);
        $registrar->clearPermissionsCollection();
        $registrar->initializeCache();
    }

    /** @param string[] $namespaces
     * @return array<string, mixed>
     */
    private function applyConfigOverrides(array $namespaces, string $packageNamespace): array
    {
        $original = [];
        $globalKeys = $this->globalConfigKeys();

        foreach ($globalKeys as $key) {
            foreach ($namespaces as $namespace) {
                $override = config("{$namespace}.{$key}");
                if (is_array($override) && $override !== []) {
                    $original[$key] ??= config($key);
                    config([$key => array_replace_recursive((array) config($key, []), $override)]);
                }
            }
        }

        $overlayNamespace = end($namespaces);
        foreach ((array) config($overlayNamespace, []) as $key => $override) {
            if (in_array($key, $globalKeys, true) || ! is_array($override)) {
                continue;
            }

            $target = "{$packageNamespace}.{$key}";
            $original[$target] ??= config($target);
            config([$target => array_replace_recursive((array) config($target, []), $override)]);
        }

        return $original;
    }

    /** @return string[] */
    private function globalConfigKeys(): array
    {
        return array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            glob(config_path('*.php')) ?: [],
        );
    }
}
