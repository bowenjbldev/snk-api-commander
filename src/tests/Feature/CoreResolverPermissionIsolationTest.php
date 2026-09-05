<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderAccountModel;
use Packages\Commander\Models\CommanderApplicationModel;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Core\Http\Middleware\CoreResolveInstanceFromHostMiddleware;
use Packages\Core\Support\CorePackageSelectorSupport;
use Spatie\Permission\PermissionRegistrar;
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

    private function readyInstance(string $clientSlug): void
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

        CommanderInstanceModel::create([
            'database' => CorePackageSelectorSupport::databaseName($clientSlug, 'kilkana'),
            'database_username' => 'tenant_1', 'database_password' => 'runtime-secret',
            'client_id' => $client->id, 'application_id' => $application->id,
            'service_status' => 'active', 'provisioning_status' => 'ready', 'provisioned_at' => now(),
        ]);
    }
}
