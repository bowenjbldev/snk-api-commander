<?php

/**
 * Coste común por petición tenant, medido dentro de UN proceso con la app cargada: lo que un
 * worker de Octane repite en cada petición, sin el sandbox de Octane ni nginx ni TLS.
 *
 * Se ejecuta dentro del contenedor `app`, leyendo el script por stdin:
 *   docker compose exec -T app php < scripts/common-cost.php
 *
 * Variables: N (iteraciones por medida, 300), BENCH_HOST (demo.commander.test),
 * BENCH_USER (admin@snake.com), BENCH_OTHER_HOST (diario-norte.commander.test).
 *
 * Crea un token para BENCH_USER y lo borra al salir; borra las filas de metering que deja
 * (ip 127.0.0.1, que nunca produce el nginx real) y las filas `route = bench`.
 */

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\ControllerDispatcher;
use Illuminate\Routing\Contracts\ControllerDispatcher as ControllerDispatcherContract;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Guard as SanctumGuard;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Core\Http\Middleware\CoreResolveInstanceFromHostMiddleware;
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

// El instante en que Laravel invoca la acción del controlador: ahí empieza el package.
class BenchDispatcher extends ControllerDispatcher
{
    public static ?float $enteredAt = null;

    public static ?float $leftAt = null;

    public function dispatch(Route $route, $controller, $method)
    {
        self::$enteredAt = hrtime(true);
        try {
            return parent::dispatch($route, $controller, $method);
        } finally {
            self::$leftAt = hrtime(true);
        }
    }
}
$app->bind(ControllerDispatcherContract::class, BenchDispatcher::class);
$matchedAt = null;
Event::listen(RouteMatched::class, function () use (&$matchedAt) { $matchedAt = hrtime(true); });

$N = max(20, (int) (getenv('N') ?: 300));
$host = getenv('BENCH_HOST') ?: 'demo.commander.test';
$otherHost = getenv('BENCH_OTHER_HOST') ?: 'diario-norte.commander.test';
$email = getenv('BENCH_USER') ?: 'admin@snake.com';
$startedAt = now('UTC')->subSecond();

// El freno de la API cuenta por IP y aquí todo llega de 127.0.0.1: sin esto, 240 iteraciones
// y el resto son 429. Solo afecta a este proceso.
config(['core.rate_limits.api_per_minute' => 100000000]);

// --- contadores -------------------------------------------------------------------------------

$sql = ['n' => 0, 'ms' => 0.0, 'by' => []];
$cache = ['hit' => 0, 'miss' => 0, 'write' => 0];
Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$sql) {
    $sql['n']++;
    $sql['ms'] += $q->time;
    $sql['by'][$q->connectionName] = ($sql['by'][$q->connectionName] ?? 0) + 1;
});
Event::listen(CacheHit::class, function () use (&$cache) { $cache['hit']++; });
Event::listen(CacheMissed::class, function () use (&$cache) { $cache['miss']++; });
Event::listen(KeyWritten::class, function () use (&$cache) { $cache['write']++; });

function resetCounters(): void
{
    global $sql, $cache;
    $sql = ['n' => 0, 'ms' => 0.0, 'by' => []];
    $cache = ['hit' => 0, 'miss' => 0, 'write' => 0];
}

/** @return array{name: string, min: float, med: float, p95: float} */
function bench(string $name, int $n, callable $fn, ?callable $before = null): array
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
    $row = ['name' => $name, 'min' => $t[0], 'med' => $t[intdiv($n, 2)], 'p95' => $t[min($n - 1, (int) floor($n * 0.95))]];
    printf("  %-58s min %7.3f  med %7.3f  p95 %7.3f ms\n", $name, $row['min'], $row['med'], $row['p95']);

    return $row;
}

function counters(int $n): string
{
    global $sql, $cache;
    $by = implode(', ', array_map(fn ($k, $v) => "{$k} ".round($v / $n, 1), array_keys($sql['by']), $sql['by']));

    return sprintf('SQL/req: %.1f consultas, %.2f ms (%s) · caché/req: %.1f hit, %.1f miss, %.1f write',
        $sql['n'] / $n, $sql['ms'] / $n, $by ?: '-', $cache['hit'] / $n, $cache['miss'] / $n, $cache['write'] / $n);
}

function hit(string $url, ?string $bearer = null): Symfony\Component\HttpFoundation\Response
{
    global $kernel;
    $server = ['HTTP_ACCEPT' => 'application/json'];
    if ($bearer !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
    }
    $request = Request::create($url, 'GET', [], [], [], $server);
    // Lo que hace FlushAuthenticationState en Octane: sin esto el guard memoriza al usuario.
    app('auth')->forgetGuards();
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response;
}

// --- instancia, credenciales tenant y token -----------------------------------------------------

$clientSlug = CorePackageSelectorSupport::subdomainFromHost($host);
$instance = CommanderInstanceModel::query()->ready()
    ->whereHas('client', fn ($q) => $q->where('slug', $clientSlug)->where('status', 'active'))
    ->whereHas('application', fn ($q) => $q->where('slug', 'kilkana')->where('status', 'active')->where('code_status', 'available'))
    ->firstOrFail();

$tenantConfig = function () use ($instance): void {
    config([
        'database.connections.tenant.database' => $instance->database,
        'database.connections.tenant.username' => $instance->database_username,
        'database.connections.tenant.password' => $instance->database_password,
    ]);
};
$onTenant = function () use ($tenantConfig): void {
    $tenantConfig();
    DB::purge('tenant');
    DB::setDefaultConnection('tenant');
};
$onCommander = function (): void {
    DB::setDefaultConnection('commander');
    DB::purge('tenant');
};

$onTenant();
$user = CoreUserModel::query()->where('email', $email)->firstOrFail();
$token = $user->createToken('bench-common-cost', ['api'], now()->addHour());
$plain = $token->plainTextToken;
$tokenId = $token->accessToken->id;
$onCommander();

printf("PHP %s · opcache %s · jit %s · cache.default=%s · metering.batch=%s · N=%d · instancia %d (%s)\n",
    PHP_VERSION, ini_get('opcache.enable_cli'), ini_get('opcache.jit') ?: 'off', config('cache.default'),
    config('core.metering.batch'), $N, $instance->id, $instance->database);

try {
    // --- peticiones completas por el kernel -----------------------------------------------------

    echo "\n== Petición completa por el kernel (resolutor + api + endpoint + metering) ==\n";
    $scenarios = [
        'público   GET public/site' => ["https://{$host}/api/v1/kilkana/public/site", null],
        'auth      GET auth/me' => ["https://{$host}/api/v1/kilkana/auth/me", $plain],
        'permiso   GET tags (can index tags)' => ["https://{$host}/api/v1/kilkana/tags", $plain],
        '404       inquilino fantasma' => ['https://nadie.commander.test/api/v1/kilkana/public/site', null],
    ];
    foreach ($scenarios as $name => [$url, $bearer]) {
        $first = hit($url, $bearer);
        printf("\n%s → HTTP %d, %d bytes\n", $name, $first->getStatusCode(), strlen((string) $first->getContent()));
        resetCounters();
        bench($name, $N, fn () => hit($url, $bearer));
        echo '    '.counters($N)."\n";
    }

    printf("\nalternancia %s / %s (public/site, tiempo por petición)\n", $clientSlug, CorePackageSelectorSupport::subdomainFromHost($otherHost));
    $urlA = "https://{$host}/api/v1/kilkana/public/site";
    $urlB = "https://{$otherHost}/api/v1/kilkana/public/site";
    printf("  B → HTTP %d\n", hit($urlB)->getStatusCode());
    resetCounters();
    $flip = false;
    bench('alternancia de inquilino, public/site', $N, function () use (&$flip, $urlA, $urlB) {
        $flip = ! $flip;
        hit($flip ? $urlA : $urlB);
    });
    echo '    '.counters($N)."\n";

    // --- entrada al package -----------------------------------------------------------------------

    echo "\n== Hasta el controlador: enrutar (middleware global + matching), middleware de ruta y package ==\n";
    $entryScenarios = [
        'público   public/site' => ["https://{$host}/api/v1/kilkana/public/site", null],
        'auth      auth/me' => ["https://{$host}/api/v1/kilkana/auth/me", $plain],
        'auth+can  tags (permiso en el controlador)' => ["https://{$host}/api/v1/kilkana/tags", $plain],
        'auth+can: settings (permiso en middleware)' => ["https://{$host}/api/v1/kilkana/settings", $plain],
    ];
    $med = function (array $values): float {
        sort($values);

        return $values[intdiv(count($values), 2)];
    };
    printf("  %-44s %8s %8s %8s %8s %8s\n", 'mediana ms', 'enrutar', 'mw ruta', 'ENTRADA', 'package', 'total');
    foreach ($entryScenarios as $name => [$url, $bearer]) {
        $routing = $routeMw = $entry = $package = $total = [];
        for ($i = 0; $i < $N; $i++) {
            $matchedAt = BenchDispatcher::$enteredAt = BenchDispatcher::$leftAt = null;
            $t0 = hrtime(true);
            hit($url, $bearer);
            $t1 = hrtime(true);
            if ($matchedAt === null || BenchDispatcher::$enteredAt === null) {
                continue;
            }
            $routing[] = ($matchedAt - $t0) / 1e6;
            $routeMw[] = (BenchDispatcher::$enteredAt - $matchedAt) / 1e6;
            $entry[] = (BenchDispatcher::$enteredAt - $t0) / 1e6;
            $package[] = (BenchDispatcher::$leftAt - BenchDispatcher::$enteredAt) / 1e6;
            $total[] = ($t1 - $t0) / 1e6;
        }
        printf("  %-44s %8.2f %8.2f %8.2f %8.2f %8.2f\n", $name, $med($routing), $med($routeMw), $med($entry), $med($package), $med($total));
    }
    echo "  ENTRADA = lo que tarda la API antes de ejecutar la acción del package; en público la conexión\n";
    echo "  tenant (~0,7 ms) se abre en la primera consulta del package y cuenta ahí.\n";

    // --- componentes aislados ---------------------------------------------------------------------

    echo "\n== Componentes aislados, coste por petición ==\n";
    $mw = app(CoreResolveInstanceFromHostMiddleware::class);
    $call = fn (string $method, mixed ...$args) => (new ReflectionMethod($mw, $method))->invoke($mw, ...$args);

    echo "\n-- resolutor --\n";
    // La consulta que el resolutor hace HOY, y al lado la que hacía antes: es la comparación que
    // justifica el SQL crudo, y hay que medir la de verdad, no una copia que se quedó atrás.
    bench('SELECT instancia: JOIN crudo (lo que hace el resolutor)', $N, fn () => CommanderInstanceModel::query()->fromQuery(
        'select i.* from commander_instances i'
        .' join commander_clients c on c.id = i.client_id'
        .' join commander_applications a on a.id = i.application_id'
        .' where i.provisioning_status = ? and c.slug = ? and c.status = ?'
        .' and a.slug = ? and a.status = ? and a.code_status = ? limit 1',
        ['ready', $clientSlug, 'active', 'kilkana', 'active', 'available'],
    )->first());
    bench('SELECT instancia: dos whereHas (lo que hacía antes)', $N, fn () => CommanderInstanceModel::query()->ready()
        ->whereHas('client', fn ($q) => $q->where('slug', $clientSlug)->where('status', 'active'))
        ->whereHas('application', fn ($q) => $q->where('slug', 'kilkana')->where('status', 'active')->where('code_status', 'available'))
        ->first());
    $raw = (string) $instance->getRawOriginal('database_password');
    bench('descifrar database_password (cast encrypted)', $N, fn () => Crypt::decryptString($raw));
    bench('glob(config/*.php) de globalConfigKeys()', $N, fn () => glob(config_path('*.php')));
    bench('applyConfigOverrides + restaurar', $N, function () use ($call) {
        $original = $call('applyConfigOverrides', ['kilkana', 'demo.kilkana'], 'kilkana');
        foreach ($original as $key => $value) {
            config([$key => $value]);
        }
    });
    bench('scopePermissionCache(db) + scopePermissionCache(null)', $N, function () use ($call, $instance) {
        $call('scopePermissionCache', $instance->database);
        $call('scopePermissionCache', null);
    });

    echo "\n-- conexión tenant --\n";
    $tenantConfig();
    bench('purge + conectar (TCP + auth + use + SET names/sql_mode)', $N, fn () => DB::connection('tenant')->getPdo(), fn () => DB::purge('tenant'));
    DB::connection('tenant')->getPdo();
    bench('SELECT 1 en conexión viva (ida y vuelta)', $N, fn () => DB::connection('tenant')->select('select 1'));
    bench('purge (cerrar) sola', $N, fn () => DB::purge('tenant'), fn () => DB::connection('tenant')->getPdo());
    DB::purge('tenant');

    echo "\n-- estado y freno (caché ".config('cache.default').") --\n";
    $state = app(CoreInstanceStateService::class);
    bench('kill-switch: estado por caché (hit)', $N, fn () => $state->current($instance->id));
    $limiter = app(RateLimiter::class);
    bench('throttle: tooManyAttempts + hit', $N, function () use ($limiter) {
        $limiter->tooManyAttempts('bench:common', 100000000);
        $limiter->hit('bench:common', 60);
    });
    $limiter->clear('bench:common');

    echo "\n-- auth (Sanctum) sobre tenant --\n";
    $onTenant();
    $authRequest = Request::create("https://{$host}/api/v1/kilkana/auth/me", 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$plain]);
    $guardTracking = new SanctumGuard(app('auth'), null, 'users', true);
    $guardSilent = new SanctumGuard(app('auth'), null, 'users', false);
    resetCounters();
    bench('token + usuario + UPDATE last_used_at (hoy)', $N, fn () => $guardTracking($authRequest));
    echo '    '.counters($N)."\n";
    resetCounters();
    bench('token + usuario, sin UPDATE', $N, fn () => $guardSilent($authRequest));
    echo '    '.counters($N)."\n";

    echo "\n-- permisos (Spatie) sobre tenant --\n";
    $registrar = app(PermissionRegistrar::class);
    config(['permission.cache.store' => 'default', 'permission.cache.key' => CorePackageSelectorSupport::permissionCacheKey($instance->database)]);
    $registrar->clearPermissionsCollection();
    $registrar->initializeCache();
    $registrar->forgetCachedPermissions();
    $t0 = hrtime(true);
    $count = $registrar->getPermissions()->count();
    printf("  reconstrucción fría (SQL + serializar + escribir caché): %.2f ms, %d permisos\n", (hrtime(true) - $t0) / 1e6, $count);
    $key = (string) config('permission.cache.key');
    bench('leer la caché (sin hidratar)', $N, fn () => Cache::get($key));
    bench('leer la caché + hidratar colección (lo que hace cada petición)', $N, fn () => $registrar->getPermissions(), fn () => $registrar->clearPermissionsCollection());
    $user = CoreUserModel::query()->findOrFail($user->getKey());
    resetCounters();
    bench('can(): hidratar + roles + permisos directos', $N, fn () => $user->can('index tags'), function () use ($registrar, $user) {
        $registrar->clearPermissionsCollection();
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');
    });
    echo '    '.counters($N)."\n";
    resetCounters();
    bench('can(): registrar caliente, solo las 2 consultas del usuario', $N, fn () => $user->can('index tags'), function () use ($user) {
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');
    });
    echo '    '.counters($N)."\n";
    bench('can(): todo caliente (segunda comprobación en la misma petición)', $N, fn () => $user->can('index tags'));
    $onCommander();
    config(['permission.cache.store' => 'array', 'permission.cache.key' => 'spatie.permission.cache']);
    $registrar->clearPermissionsCollection();
    $registrar->initializeCache();

    echo "\n-- metering (commander, conexión viva) --\n";
    $row = fn () => [
        'instance_id' => $instance->id, 'created_at' => now('UTC'), 'updated_at' => now('UTC'), 'method' => 'GET',
        'route' => 'bench', 'path' => 'bench', 'status' => 200, 'duration_ms' => 1, 'user_id' => null,
        'token_id' => null, 'ip' => '127.0.0.1', 'request_bytes' => 0, 'response_bytes' => 0, 'memory_kb' => 1,
    ];
    bench('INSERT de 1 fila (lote 1, FPM)', $N, fn () => CommanderRequestLogModel::query()->insert([$row()]));
    bench('INSERT de 20 filas (lote 20, Octane; coste de 20 peticiones)', max(20, intdiv($N, 5)),
        fn () => CommanderRequestLogModel::query()->insert(array_map(fn () => $row(), range(1, 20))));
} finally {
    app(CoreMeteringBufferSupport::class)->flush();
    $onCommander();
    $deleted = CommanderRequestLogModel::query()
        ->where('instance_id', $instance->id)
        ->where('created_at', '>=', $startedAt)
        ->where(fn ($q) => $q->where('ip', '127.0.0.1')->orWhere('route', 'bench'))
        ->delete();
    $tenantConfig();
    DB::purge('tenant');
    DB::connection('tenant')->table('personal_access_tokens')->where('id', $tokenId)->delete();
    DB::purge('tenant');
    printf("\nlimpieza: %d filas de metering borradas, token %d borrado\n", $deleted, $tokenId);
}
