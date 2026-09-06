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

    public function test_sirve_copias_mientras_la_cache_no_cambia_y_se_descarta_cuando_cambia(): void
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
}
