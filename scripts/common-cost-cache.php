<?php

/**
 * Qué cuesta mover la caché de `file` a `database`: es el prerrequisito de una segunda réplica,
 * porque `file` es local al contenedor y de ella cuelgan el freno, el kill-switch y la caché de
 * permisos de Spatie.
 *
 *   docker compose exec -T -e CACHE_STORE=file     app php < scripts/common-cost-cache.php
 *   docker compose exec -T -e CACHE_STORE=database app php < scripts/common-cost-cache.php
 *
 * La primera parte compara los dos stores en crudo y sale igual en las dos corridas. La segunda
 * mide lo integrado —freno, kill-switch y peticiones completas— con el store que la aplicación
 * tenga por defecto, y por eso hacen falta las dos.
 */

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Core\Models\CoreUserModel;
use Packages\Core\Services\CoreInstanceStateService;
use Packages\Core\Support\CoreMeteringBufferSupport;
use Packages\Core\Support\CorePackageSelectorSupport;
use Spatie\Permission\PermissionRegistrar;

$base = '/var/www/src';
chdir($base);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$N = max(20, (int) (getenv('N') ?: 300));
$host = getenv('BENCH_HOST') ?: 'demo.commander.test';
$email = getenv('BENCH_USER') ?: 'admin@snake.com';
$startedAt = now('UTC')->subSecond();
config(['core.rate_limits.api_per_minute' => 100000000]);

function bench(string $name, int $n, callable $fn, ?callable $before = null): float
{
    $t = [];
    for ($i = 0; $i < $n; $i++) {
        if ($before !== null) {
            $before();
        }
        $s = hrtime(true);
        $fn();
        $t[] = (hrtime(true) - $s) / 1e6;
    }
    sort($t);
    printf("  %-56s med %7.3f  p95 %7.3f ms\n", $name, $t[intdiv($n, 2)], $t[min($n - 1, (int) floor($n * 0.95))]);

    return $t[intdiv($n, 2)];
}

$clientSlug = CorePackageSelectorSupport::subdomainFromHost($host);
$instance = CommanderInstanceModel::query()->ready()
    ->whereHas('client', fn ($q) => $q->where('slug', $clientSlug))
    ->whereHas('application', fn ($q) => $q->where('slug', 'kilkana'))
    ->firstOrFail();
$tenantConfig = fn () => config([
    'database.connections.tenant.database' => $instance->database,
    'database.connections.tenant.username' => $instance->database_username,
    'database.connections.tenant.password' => $instance->database_password,
]);
$tenantConfig();
DB::purge('tenant');
DB::setDefaultConnection('tenant');
$user = CoreUserModel::query()->where('email', $email)->firstOrFail();
$token = $user->createToken('bench-cache', ['api'], now()->addHour());
$plain = $token->plainTextToken;
$tokenId = $token->accessToken->id;
DB::setDefaultConnection('commander');
DB::purge('tenant');

$default = (string) config('cache.default');
printf("cache.default=%s · conexión del store database=%s · jit=%s · N=%d\n\n",
    $default, config('cache.stores.database.connection'), ini_get('opcache.jit') ?: 'off', $N);

function hit(string $url, ?string $bearer = null): int
{
    global $kernel;
    $server = ['HTTP_ACCEPT' => 'application/json'];
    if ($bearer !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
    }
    $request = Request::create($url, 'GET', [], [], [], $server);
    app('auth')->forgetGuards();
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response->getStatusCode();
}

try {
    echo "== Operaciones crudas del store (igual en las dos corridas) ==\n";
    foreach (['file', 'database'] as $name) {
        echo "-- {$name} --\n";
        $s = Cache::store($name);
        $s->put('bench:probe', ['id' => 5, 'status' => 'active', 'texto' => str_repeat('a', 120)], 300);
        bench("{$name}: get de una clave presente", $N, fn () => $s->get('bench:probe'));
        bench("{$name}: put", $N, fn () => $s->put('bench:probe2', 'x', 300));
        $s->put('bench:counter', 1, 300);
        bench("{$name}: increment (lo que hace el freno)", $N, fn () => $s->increment('bench:counter'));
        foreach (['bench:probe', 'bench:probe2', 'bench:counter'] as $k) {
            $s->forget($k);
        }
    }

    echo "\n== El payload de permisos de Spatie por cada store ==\n";
    $tenantConfig();
    DB::purge('tenant');
    DB::setDefaultConnection('tenant');
    $permKey = CorePackageSelectorSupport::permissionCacheKey($instance->database);
    config(['permission.cache.store' => 'default', 'permission.cache.key' => $permKey]);
    $registrar = app(PermissionRegistrar::class);
    $registrar->clearPermissionsCollection();
    $registrar->initializeCache();
    $registrar->getPermissions();
    $payload = Cache::store($default)->get($permKey);
    printf("  payload serializado: %.1f KB (63 permisos, 7 roles), store por defecto: %s\n",
        strlen(serialize($payload)) / 1024, $default);
    foreach (['file', 'database'] as $name) {
        Cache::store($name)->put($permKey.'.probe', $payload, 300);
        bench("{$name}: leer el payload de permisos", $N, fn () => Cache::store($name)->get($permKey.'.probe'));
        Cache::store($name)->forget($permKey.'.probe');
    }
    bench("por defecto ({$default}): leer + comparar + copiar (lo que hace la memo)", $N,
        fn () => $registrar->getPermissions(), fn () => $registrar->clearPermissionsCollection());
    DB::setDefaultConnection('commander');
    DB::purge('tenant');
    config(['permission.cache.store' => 'array', 'permission.cache.key' => 'spatie.permission.cache']);
    $registrar->clearPermissionsCollection();
    $registrar->initializeCache();

    echo "\n== Integrado, con el store por defecto ({$default}) ==\n";
    $state = app(CoreInstanceStateService::class);
    $state->current($instance->id);
    bench('kill-switch: estado por caché (hit)', $N, fn () => $state->current($instance->id));
    $limiter = app(RateLimiter::class);
    bench('freno: tooManyAttempts + hit', $N, function () use ($limiter) {
        $limiter->tooManyAttempts('bench:cache', 100000000);
        $limiter->hit('bench:cache', 60);
    });
    $limiter->clear('bench:cache');

    echo "\n== Peticiones completas por el kernel ==\n";
    foreach ([
        'público   public/site' => ["https://{$host}/api/v1/kilkana/public/site", null],
        'auth      auth/me' => ["https://{$host}/api/v1/kilkana/auth/me", $plain],
        'auth+can  tags' => ["https://{$host}/api/v1/kilkana/tags", $plain],
    ] as $name => [$url, $bearer]) {
        $status = hit($url, $bearer);
        bench("{$name} (HTTP {$status})", $N, fn () => hit($url, $bearer));
    }
} finally {
    app(CoreMeteringBufferSupport::class)->flush();
    DB::setDefaultConnection('commander');
    DB::purge('tenant');
    $borradas = CommanderRequestLogModel::query()
        ->where('instance_id', $instance->id)->where('created_at', '>=', $startedAt)
        ->where('ip', '127.0.0.1')->delete();
    $filas = DB::connection('commander')->table('cache')->count();
    $locks = DB::connection('commander')->table('cache_locks')->count();
    $tenantConfig();
    DB::purge('tenant');
    DB::connection('tenant')->table('personal_access_tokens')->where('id', $tokenId)->delete();
    $enTenant = DB::connection('tenant')->table('cache')->count();
    DB::purge('tenant');
    printf("\nlimpieza: %d filas de metering, token %d borrado · commander.cache=%d filas, cache_locks=%d · %s.cache=%d (debe ser 0)\n",
        $borradas, $tokenId, $filas, $locks, $instance->database, $enTenant);
}
