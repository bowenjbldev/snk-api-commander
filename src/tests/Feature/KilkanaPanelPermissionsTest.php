<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Packages\Kilkana\Database\Seeders\SnkKilkanaDatabaseSeeder;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Quién abre el panel del periódico, comprobado sobre el reparto de roles y no sobre una lista
 * escrita al lado.
 *
 * `index posts` es la puerta de todo lo que el panel lee, y desde que existe `GET plan` también
 * la de la facturación. La ruta declara su `can:` —eso lo fija `KilkanaPlanTest`— pero **una
 * declaración no dice quién pasa**: el día que alguien le dé ese permiso a `reader` en el
 * seeder, la ruta seguiría declarándolo igual y los lectores del periódico leerían el plan.
 *
 * Y hay lectores con token: `CoreAuthController::login` no mira el rol, así que una cuenta de
 * comunidad se autentica igual que una de redacción. Lo único que la para es esto.
 */
class KilkanaPanelPermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // `tenant` es sqlite `:memory:` en pruebas, así que basta migrar encima.
        DB::setDefaultConnection('tenant');
        foreach (['Core', 'Kilkana'] as $package) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$package}/database/migrations",
            ])->run();
        }

        app(SnkKilkanaDatabaseSeeder::class)->run();
    }

    public function test_all_six_newsroom_roles_have_the_panel_permission(): void
    {
        $canList = fn (string $role) => Role::findByName($role, 'sanctum')->hasPermissionTo('index posts');

        // Los seis que abren el panel. La tarjeta de plan se le enseña a toda la redacción por
        // decisión del usuario, así que la puerta del endpoint tiene que dejar pasar a los seis:
        // colgarla de `show settings` dejaría fuera a cuatro de ellos.
        foreach (['admin', 'director', 'editor', 'redactor', 'colaborador', 'moderador'] as $role) {
            $this->assertTrue($canList($role), "El rol {$role} abre el panel y no puede listar piezas.");
        }
    }

    /**
     * El sumario de la redacción es de quien está en la sala, y eso lo decide el rol.
     *
     * Son **dos preguntas y hasta hoy un solo permiso**: `edit others posts` contesta «puedes
     * cambiar la pieza de otro» y `view others posts` contesta «puedes saber que existe». Un
     * redactor y un moderador tienen la segunda sin la primera, que es justo el reparto que un
     * permiso único no sabe expresar.
     *
     * Esto no lo puede fijar la ruta: `index posts` la abren los seis, y lo que separa a la
     * plantilla de quien entrega desde fuera es este reparto y nada más.
     */
    public function test_viewing_and_editing_others_posts_are_separate_permissions(): void
    {
        $has = fn (string $role, string $permission) => Role::findByName($role, 'sanctum')
            ->hasPermissionTo($permission);

        foreach (['admin', 'director', 'editor', 'redactor', 'moderador'] as $role) {
            $this->assertTrue($has($role, 'view others posts'), "El rol {$role} está en la sala y no ve el sumario.");
        }

        // Quien entrega desde fuera ve lo suyo: ni el sumario ni la copia de nadie.
        $this->assertFalse($has('colaborador', 'view others posts'));
        $this->assertFalse($has('colaborador', 'edit others posts'));

        // Y ver no arrastra editar: sin esto, conceder el sumario a la plantilla le daría de
        // paso la pieza de cualquiera.
        $this->assertFalse($has('redactor', 'edit others posts'));
        $this->assertFalse($has('moderador', 'edit others posts'));
    }

    /**
     * Reaplicar el reparto REPARA, que es de lo que vive `kilkana:sync-permissions`.
     *
     * Un despliegue no vuelve a sembrar —`core:migrate --all-tenants` solo siembra con `--fresh`—, así
     * que un permiso nuevo no llega solo a un periódico que ya existía. El comando reaplica
     * este catálogo sobre cada inquilino, y lo que lo hace seguro es que sea idempotente y
     * que un rol al que le falte algo vuelva entero.
     */
    public function test_reapplying_the_grants_restores_a_missing_role_permission(): void
    {
        $writer = Role::findByName('redactor', 'sanctum');
        $before = $writer->permissions->pluck('name')->sort()->values()->all();

        $writer->revokePermissionTo('view others posts');
        $this->assertFalse($writer->fresh()->hasPermissionTo('view others posts'));

        app(SnkKilkanaRoleCatalogService::class)->sync();

        $after = Role::findByName('redactor', 'sanctum')->permissions->pluck('name')->sort()->values()->all();
        $this->assertSame($before, $after, 'el rol tiene que volver exactamente a como estaba');
    }

    public function test_a_reader_is_stopped_at_the_gate_even_when_signed_in(): void
    {
        $reader = Role::findByName('reader', 'sanctum');

        // Un lector registrado existe para comentar, y eso es TODO lo que puede. Si algún día
        // gana `index posts`, esta prueba se pone roja antes de que lea la facturación del
        // periódico —plan, precio, asientos y banda— por `GET plan`.
        $this->assertFalse($reader->hasPermissionTo('index posts'));
        $this->assertSame(['create comments'], $reader->permissions->pluck('name')->all());
    }

    /** Un redactor escribe y manda a revision. Publicar es de la mesa: es lo que separa los dos papeles. */
    public function test_a_writer_cannot_publish(): void
    {
        $this->assertFalse(Role::findByName('redactor', 'sanctum')->hasPermissionTo('publish posts'));
        $this->assertTrue(Role::findByName('editor', 'sanctum')->hasPermissionTo('publish posts'));
    }
}
