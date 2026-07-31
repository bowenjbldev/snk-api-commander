<?php

namespace Packages\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Core\Support\CoreActivePackageSupport;
use Packages\Core\Support\CorePackageSelectorSupport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Centralizado, cliente-primero: el SUBDOMINIO es el CLIENTE ({client}.{BASE_DOMAIN}) y el SLUG
 * del package va en el path (/api/v1/{slug}). El resolutor recibe el slug como argumento de
 * middleware (lo conoce el registro de rutas) y el cliente lo deriva del host; juntos resuelven la
 * fila viva de commander_packages (subdomain del cliente + slug) y la fijan como package activo
 * (lo leen state, metering y cuotas). Sin fila provisionada => 404.
 *
 * Aplica config compartida ({slug}) y luego el overlay ({cliente}.{slug}); restaura config,
 * conexión y contexto en finally para no cruzar tenants en workers persistentes.
 */
class CoreResolvePackageFromHostMiddleware
{
    public function __construct(private readonly CoreActivePackageSupport $active) {}

    public function handle(Request $request, Closure $next, string $slug): Response
    {
        // El registro vive en la BD commander; CommanderPackageModel está pinneado a esa conexión,
        // así que resuelve aunque luego cambiemos la conexión default a la del tenant.
        $clientSubdomain = CorePackageSelectorSupport::subdomainFromHost($request->getHost());

        $package = $clientSubdomain !== null
            ? CommanderPackageModel::query()
                ->whereNotNull('provisioned_at')
                ->whereHas('client', fn ($q) => $q->where('slug', $clientSubdomain)->where('status', 'active'))
                ->where('slug', $slug)
                ->first()
            : null;

        if ($package === null) {
            abort(404); // (cliente, slug) no provisionado
        }

        if (empty($package->database) || empty($package->database_username) || empty($package->database_password)) {
            abort(503, 'Tenant database is not available.');
        }

        try {
            CorePackageSelectorSupport::validatedDatabaseIdentifier($package->database);
            CorePackageSelectorSupport::validatedDatabaseIdentifier($package->database_username);
        } catch (\RuntimeException) {
            abort(503, 'Tenant database is not available.');
        }

        $originalConnection = DB::getDefaultConnection();
        $originalTenantConfig = config('database.connections.tenant');
        $originalConfig = [];

        try {
            $this->active->set($package);

            // Aislamiento FÍSICO: conmuta la conexión default a la BD del package ({client}_{slug}).
            config([
                'database.connections.tenant.database' => $package->database,
                'database.connections.tenant.username' => $package->database_username,
                'database.connections.tenant.password' => $package->database_password,
            ]);
            DB::purge('tenant');
            DB::setDefaultConnection('tenant');

            $packageConfigKey = strtolower(Str::studly($slug));
            $clientConfigKey = strtolower(Str::studly($clientSubdomain));
            $originalConfig = $this->applyConfigOverrides(
                [$packageConfigKey, "{$clientConfigKey}.{$packageConfigKey}"],
                $packageConfigKey,
            );

            return $next($request);
        } finally {
            foreach ($originalConfig as $key => $value) {
                config([$key => $value]);
            }
            config(['database.connections.tenant' => $originalTenantConfig]);
            DB::setDefaultConnection($originalConnection);
            DB::purge('tenant');
            $this->active->forget();
        }
    }

    /**
     * Fusiona primero el config compartido y después el overlay, guardando lo que debe restaurarse.
     *
     * @param  string[]  $namespaces
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

        // La config propia del overlay (acme.shop.features) se expone donde la consume
        // el package base (shop.features), sin dejarla aplicada al siguiente request.
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

    /** Claves del skeleton de Laravel (config/*.php): las únicas que un package puede overridear. */
    private function globalConfigKeys(): array
    {
        return array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            glob(config_path('*.php')) ?: [],
        );
    }
}
