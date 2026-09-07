<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderAccountModel;
use Packages\Commander\Models\CommanderApplicationModel;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Core\Enums\CoreServiceStatusEnum;
use Packages\Core\Http\Middleware\CoreEnsureActiveMiddleware;
use Packages\Core\Http\Middleware\CoreResolveInstanceFromHostMiddleware;
use Packages\Core\Services\CoreInstanceStateService;
use Packages\Core\Support\CoreActiveInstanceSupport;
use Packages\Core\Support\CorePackageSelectorSupport;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Un proceso que sirve dos inquilinos seguidos (worker persistente, CLI que recorre bases) no
 * puede arrastrar la colección de permisos del primero al segundo: el registrar de Spatie la
 * guarda en memoria y la caché por clave de base no la toca.
 */
class CoreResolverPermissionIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_permission_collection_does_not_survive_a_tenant_switch(): void
    {
        $this->readyInstance('north');
        $this->readyInstance('south');

        $this->assertSame(['only-north'], $this->permissionsSeenBy('north.commander.test', 'only-north'));
        $this->assertSame(['only-south'], $this->permissionsSeenBy('south.commander.test', 'only-south'));
        // Y de vuelta: lo que el registrar recuerda por worker es lo de north, no lo de south.
        $this->assertSame(['only-north'], $this->permissionsSeenBy('north.commander.test', 'only-north'));
    }

    public function test_resolved_state_ignores_stale_cache_without_another_query(): void
    {
        $instance = $this->readyInstance('north');
        $state = app(CoreInstanceStateService::class);
        $this->assertSame(CoreServiceStatusEnum::Active, $state->current($instance->id)->status);
        $instance->update(['service_status' => 'suspended', 'reason' => 'operator']);
        $request = Request::create('https://north.commander.test/api/v1/kilkana/x');

        $response = app(CoreResolveInstanceFromHostMiddleware::class)->handle($request, function ($request) {
            Cache::shouldReceive('remember')->never();
            DB::connection('commander')->enableQueryLog();
            DB::connection('commander')->flushQueryLog();
            $response = app(CoreEnsureActiveMiddleware::class)->handle($request, fn () => response('must not run'));
            $this->assertSame([], DB::connection('commander')->getQueryLog());

            return $response;
        }, 'kilkana');

        $this->assertSame(402, $response->getStatusCode());
        $this->assertSame('operator', $response->getData(true)['reason']);
        $this->assertNull(app(CoreActiveInstanceSupport::class)->current());
    }

    public function test_explicit_state_and_state_changes_do_not_reuse_an_old_active_snapshot(): void
    {
        $north = $this->readyInstance('north');
        $south = $this->readyInstance('south');
        $active = app(CoreActiveInstanceSupport::class);
        $active->set($north);
        $state = app(CoreInstanceStateService::class);

        $south->update(['service_status' => 'suspended']);
        $this->assertSame(CoreServiceStatusEnum::Suspended, $state->current($south->id)->status);
        $this->assertSame(CoreServiceStatusEnum::Active, $state->current()->status);
        $until = now()->addHour()->toImmutable();
        $changed = $state->set(CoreServiceStatusEnum::Grace, 'quota', 'notice', $until, $north->id);
        $this->assertSame(CoreServiceStatusEnum::Grace, $state->current()->status);
        $this->assertEquals($changed, $state->current());

        $state->set(CoreServiceStatusEnum::Suspended, instanceId: $north->id);
        $this->assertSame(CoreServiceStatusEnum::Suspended, $state->current()->status);
        $this->assertNull($state->current()->graceUntil);
        $active->forget();
    }

    public function test_resolved_grace_preserves_headers_and_expires_without_a_write(): void
    {
        $instance = $this->readyInstance('north');
        $instance->update(['service_status' => 'grace', 'grace_until' => now()->addHour(), 'message' => 'notice']);
        $request = Request::create('https://north.commander.test/api/v1/kilkana/x');
        $read = fn () => app(CoreResolveInstanceFromHostMiddleware::class)->handle($request,
            fn ($request) => app(CoreEnsureActiveMiddleware::class)->handle($request, fn () => response('ok')), 'kilkana');

        $response = $read();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('grace', $response->headers->get('X-Instance-Status'));
        $this->assertSame('notice', $response->headers->get('X-Instance-Message'));
        $this->assertSame($instance->grace_until->toIso8601String(), $response->headers->get('X-Instance-Grace-Until'));
        $this->travel(2)->hours();
        $this->assertSame(402, $read()->getStatusCode());
        $this->assertSame(CoreServiceStatusEnum::Grace, $instance->fresh()->service_status);
        $this->travelBack();
    }

    public function test_resolver_rechecks_registry_and_credentials_on_every_request(): void
    {
        $instance = $this->readyInstance('north');
        $request = Request::create('https://north.commander.test/api/v1/kilkana/x');
        $resolver = app(CoreResolveInstanceFromHostMiddleware::class);
        $read = fn () => $resolver->handle($request, fn () => response(config('database.connections.tenant.username')), 'kilkana');
        $this->assertSame('tenant_1', $read()->getContent());
        $instance->update(['database_username' => 'tenant_2']);
        $this->assertSame('tenant_2', $read()->getContent());

        foreach ([
            [$instance, 'provisioning_status', 'deprovisioned', 'ready'],
            [$instance->client, 'status', 'inactive', 'active'],
            [$instance->application, 'code_status', 'missing', 'available'],
        ] as [$model, $column, $blocked, $restored]) {
            $model->update([$column => $blocked]);
            try {
                $read();
                $this->fail('A stale registry entry was routed.');
            } catch (NotFoundHttpException $error) {
                $this->assertSame(404, $error->getStatusCode());
            }
            $model->update([$column => $restored]);
            $this->assertSame('tenant_2', $read()->getContent());
        }
    }

    public function test_tenant_connection_is_discarded_on_success_and_exception_but_commander_is_reused(): void
    {
        $this->readyInstance('north');
        $request = Request::create('https://north.commander.test/api/v1/kilkana/x');
        $original = config('database.connections.tenant');
        $commander = DB::connection('commander')->getPdo();
        $default = DB::getDefaultConnection();
        $connection = null;

        foreach ([false, true, false] as $fail) {
            try {
                app(CoreResolveInstanceFromHostMiddleware::class)->handle($request, function () use ($fail, &$connection) {
                    config(['database.connections.tenant.database' => ':memory:']);
                    $connection = DB::connection('tenant');
                    $this->assertFalse($connection->getPdo()->inTransaction());
                    if ($fail) {
                        $connection->beginTransaction();
                        throw new \RuntimeException('diagnostic exception');
                    }

                    return response('ok');
                }, 'kilkana');
            } catch (\RuntimeException $error) {
                $this->assertTrue($fail);
                $this->assertSame('diagnostic exception', $error->getMessage());
            }
            $this->assertNull($connection->getRawPdo());
            $this->assertArrayNotHasKey('tenant', DB::getConnections());
            $this->assertSame($commander, DB::connection('commander')->getPdo());
            $this->assertSame($original, config('database.connections.tenant'));
            $this->assertSame($default, DB::getDefaultConnection());
            $this->assertNull(app(CoreActiveInstanceSupport::class)->current());
        }
    }

    /** Corre el resolutor sobre un tenant sqlite recién creado con UN permiso y devuelve lo que ve el registrar. */
    private function permissionsSeenBy(string $host, string $permission): array
    {
        $request = Request::create("https://{$host}/api/v1/kilkana/x", 'GET');
        $seen = [];

        app(CoreResolveInstanceFromHostMiddleware::class)->handle($request, function () use (&$seen, $permission) {
            // El resolutor pone el nombre MariaDB de la base; en sqlite eso sería un fichero.
            config(['database.connections.tenant.database' => ':memory:']);
            DB::purge('tenant');
            $this->artisan('migrate', ['--database' => 'tenant', '--path' => 'packages/Core/database/migrations'])->run();

            // Inserción cruda: el modelo de Spatie vaciaría el registrar al guardar, y una petición
            // normal no crea permisos, los lee.
            DB::table('permissions')->insert(['name' => $permission, 'guard_name' => 'sanctum']);
            $seen = app(PermissionRegistrar::class)->getPermissions()->pluck('name')->all();

            return response('ok');
        }, 'kilkana');

        return $seen;
    }

    private function readyInstance(string $clientSlug): CommanderInstanceModel
    {
        $account = CommanderAccountModel::create(['name' => strtoupper($clientSlug), 'email' => "ops@{$clientSlug}.test"]);
        $client = CommanderClientModel::create([
            'account_id' => $account->id, 'slug' => $clientSlug,
            'name' => strtoupper($clientSlug), 'email' => "ops@{$clientSlug}.test", 'status' => 'active',
        ]);
        $application = CommanderApplicationModel::firstOrCreate(
            ['slug' => 'kilkana'],
            ['package_name' => 'Kilkana', 'name' => 'Kilkana', 'status' => 'active', 'code_status' => 'available'],
        );

        return CommanderInstanceModel::create([
            'database' => CorePackageSelectorSupport::databaseName($clientSlug, 'kilkana'),
            'database_username' => 'tenant_1', 'database_password' => 'runtime-secret',
            'client_id' => $client->id, 'application_id' => $application->id,
            'service_status' => 'active', 'provisioning_status' => 'ready', 'provisioned_at' => now(),
        ]);
    }
}
