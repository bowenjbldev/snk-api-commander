<?php

namespace Packages\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Core\Support\CoreActiveInstanceSupport;
use Packages\Core\Support\CorePackageSelectorSupport;
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
        }
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
