<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cuánto cuesta un `can()`: un permiso que concede el rol se resuelve con la consulta de los
 * roles y nada más; el permiso directo solo se mira cuando el rol dice que no.
 */
class CoreUserPermissionQueriesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // `tenant` es sqlite `:memory:` en pruebas, así que basta migrar encima.
        DB::setDefaultConnection('tenant');
        $this->artisan('migrate', ['--database' => 'tenant', '--path' => 'packages/Core/database/migrations'])->run();

        $permission = Permission::create(['name' => 'index tags', 'guard_name' => 'sanctum']);
        Role::create(['name' => 'editor', 'guard_name' => 'sanctum'])->givePermissionTo($permission);
    }

    public function test_a_permission_granted_by_a_role_costs_only_the_roles_query(): void
    {
        $user = $this->user('editor@tenant.test');
        $user->assignRole('editor');

        $this->assertSame(['model_has_roles'], $this->tablesQueriedBy($user, 'index tags', true));
    }

    public function test_without_the_role_direct_permissions_are_checked_and_still_apply(): void
    {
        $user = $this->user('nobody@tenant.test');
        $this->assertSame(['model_has_roles', 'model_has_permissions'], $this->tablesQueriedBy($user, 'index tags', false));

        $user->givePermissionTo('index tags');
        $this->assertSame(['model_has_roles', 'model_has_permissions'], $this->tablesQueriedBy($user, 'index tags', true));
    }

    /** @return list<string> las tablas pivote que consulta un `can()` recién cargado el usuario */
    private function tablesQueriedBy(CoreUserModel $user, string $permission, bool $expected): array
    {
        // El registrar caliente, como en la segunda petición de un worker: lo que se cuenta es
        // lo que cuesta el usuario, no la lista de permisos.
        app(PermissionRegistrar::class)->getPermissions();
        $user = CoreUserModel::query()->findOrFail($user->getKey());

        $connection = DB::connection('tenant');
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $this->assertSame($expected, $user->can($permission));
        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();

        return array_values(array_filter(array_map(
            fn (array $query) => preg_match('/"?(model_has_roles|model_has_permissions)"?/', $query['query'], $m) === 1 ? $m[1] : null,
            $queries,
        )));
    }

    private function user(string $email): CoreUserModel
    {
        return CoreUserModel::create(['name' => 'Someone', 'email' => $email, 'password' => 'a-password-of-twelve']);
    }
}
