<?php

// docker compose exec -T app php < scripts/core-resolver-check.php
// Local MariaDB check: no business writes, tokens, provisioning or HTTP runtime changes.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Core\Http\Middleware\CoreResolveInstanceFromHostMiddleware;
use Packages\Core\Services\CoreInstanceStateService;
use Packages\Core\Support\CoreActiveInstanceSupport;

require '/var/www/src/vendor/autoload.php';
$app = require '/var/www/src/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function checkResolver(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}

checkResolver($app->environment('local'), 'Only APP_ENV=local is allowed.');
$domain = config('core.tenant_base_domain');
checkResolver(is_string($domain) && str_ends_with($domain, '.test'), 'Only local .test hosts are allowed.');
checkResolver(DB::connection('commander')->getDriverName() === 'mysql', 'This check requires MariaDB/MySQL.');
$targets = CommanderInstanceModel::query()->ready()
    ->whereHas('client', fn ($q) => $q->where('status', 'active'))
    ->whereHas('application', fn ($q) => $q->where('status', 'active')->where('code_status', 'available'))
    ->with(['client:id,slug', 'application:id,slug'])->orderBy('id')->limit(3)->get();
checkResolver($targets->pluck('client_id')->unique()->count() >= 2, 'At least two ready tenants are required.');
$original = config('database.connections.tenant');
$default = DB::getDefaultConnection();
$commander = DB::connection('commander');
$pdo = $commander->getPdo();
$commander->enableQueryLog();
$connections = [];
$resolver = app(CoreResolveInstanceFromHostMiddleware::class);
$active = app(CoreActiveInstanceSupport::class);
$samples = [];
for ($n = 0; $n < 330; $n++) {
    $target = $targets[$n % $targets->count()];
    $request = Request::create('https://'.$target->client->slug.'.'.$domain.'/api/v1/'.$target->application->slug.'/check');
    $commander->flushQueryLog();
    $tenant = null;
    $started = hrtime(true);
    try {
        $resolver->handle($request, function () use ($target, $n, &$tenant, &$connections) {
            checkResolver(app(CoreInstanceStateService::class)->current()->status === $target->service_status, 'Wrong resolved state.');
            $tenant = DB::connection('tenant');
            checkResolver(! $tenant->getPdo()->inTransaction(), 'Leaked transaction.');
            $row = $tenant->selectOne('SELECT DATABASE() AS db, CONNECTION_ID() AS id, @commander_resolver_probe AS probe');
            checkResolver($row->db === $target->database && $row->probe === null, 'Wrong database or leaked session state.');
            checkResolver(! isset($connections[$row->id]), 'Tenant connection was reused.');
            $connections[$row->id] = true;
            $tenant->statement('SET @commander_resolver_probe = 1');
            if ($n % 5 === 0) {
                $tenant->beginTransaction();
                throw new RuntimeException('expected resolver check exception');
            }

            return response('ok');
        }, $target->application->slug);
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'expected resolver check exception') {
            throw $error;
        }
    }
    checkResolver($tenant?->getRawPdo() === null && ! isset(DB::getConnections()['tenant']), 'Tenant was not purged.');
    checkResolver($commander->getPdo() === $pdo, 'Commander connection was replaced.');
    checkResolver(count($commander->getQueryLog()) === 1, 'Expected exactly one fresh Commander query per resolution.');
    checkResolver($active->current() === null && DB::getDefaultConnection() === $default && config('database.connections.tenant') === $original, 'Context was not restored.');
    if ($n >= 30) {
        $samples[] = (hrtime(true) - $started) / 1e6;
    }
}
sort($samples);
echo json_encode([
    'checks' => 'passed', 'tenants' => $targets->pluck('client_id')->unique()->count(),
    'iterations' => 330, 'tenant_connections' => count($connections), 'commander_connections' => 1,
    'measured_iterations' => count($samples), 'median_ms' => ($samples[149] + $samples[150]) / 2,
    'p95_ms' => $samples[284], 'scope' => 'Sequential CLI with session/transaction probes; not HTTP throughput.',
], JSON_PRETTY_PRINT).PHP_EOL;
