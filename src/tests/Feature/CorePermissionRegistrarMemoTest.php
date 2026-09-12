<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La memoria por worker del registrar sirve copias mientras la caché no cambia, y se descarta
 * sola en cuanto cambia: editar un rol tiene que verse en la siguiente petición, no en el
 * siguiente reciclado del worker.
 */
class CorePermissionRegistrarMemoTest extends TestCase
{
    private PermissionRegistrar $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        // `tenant` es sqlite `:memory:` en pruebas, así que basta migrar encima.
        DB::setDefaultConnection('tenant');
        $this->artisan('migrate', ['--database' => 'tenant', '--path' => 'packages/Core/database/migrations'])->run();

        // La clave por base que pone el resolutor; la caché `array` de las pruebas persiste
        // dentro del proceso, como la `file` de un worker.
        config(['permission.cache.store' => 'default', 'permission.cache.key' => 'spatie.permission.cache.memo_test']);
        $this->registrar = app(PermissionRegistrar::class);
        $this->registrar->clearPermissionsCollection();
        $this->registrar->initializeCache();
        $this->registrar->forgetCachedPermissions();
    }

    public function test_serves_copies_while_the_cache_is_unchanged_and_drops_them_when_it_changes(): void
    {
        // Inserción cruda: el modelo de Spatie vaciaría la caché al guardar, y lo que se prueba
        // es qué pasa cuando NO se vacía.
        DB::table('permissions')->insert(['name' => 'first', 'guard_name' => 'sanctum']);
        $first = $this->registrar->getPermissions()->first();
        $this->assertSame('first', $first->name);

        // Segunda petición: mismo contenido en caché, de la memoria, y una copia distinta.
        $this->registrar->clearPermissionsCollection();
        $again = $this->registrar->getPermissions()->first();
        $this->assertSame('first', $again->name);
        $this->assertNotSame($first, $again, 'La memoria entrega el mismo objeto a dos peticiones.');

        // Lo que hace Spatie al guardar un permiso o un rol: vaciar la caché.
        DB::table('permissions')->insert(['name' => 'second', 'guard_name' => 'sanctum']);
        $this->registrar->forgetCachedPermissions();
        $this->assertSame(['first', 'second'], $this->registrar->getPermissions()->pluck('name')->sort()->values()->all());
    }

    public function test_nested_roles_and_their_collection_do_not_survive_a_request(): void
    {
        $permission = DB::table('permissions')->insertGetId(['name' => 'read', 'guard_name' => 'sanctum']);
        foreach (['editor', 'reader'] as $name) {
            $role = DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'sanctum']);
            DB::table('role_has_permissions')->insert(['permission_id' => $permission, 'role_id' => $role]);
        }

        $first = $this->registrar->getPermissions()->first();
        $first->name = 'changed-in-memory';
        $role = $first->roles->first();
        $role->name = 'changed-in-memory';
        $first->roles->pop();

        $this->registrar->clearPermissionsCollection();
        $again = $this->registrar->getPermissions()->first();
        $this->assertSame('read', $again->name);
        $this->assertSame(['editor', 'reader'], $again->roles->pluck('name')->sort()->values()->all());
        $this->assertNotSame($first->roles, $again->roles);
        $this->assertNotSame($role, $again->roles->first());

        DB::table('role_has_permissions')->where('permission_id', $permission)->delete();
        $this->registrar->forgetCachedPermissions();
        $this->assertCount(0, $this->registrar->getPermissions()->first()->roles);
    }
}
