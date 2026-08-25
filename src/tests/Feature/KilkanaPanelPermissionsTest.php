<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Packages\Kilkana\Database\Seeders\SnkKilkanaDatabaseSeeder;
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
        foreach (['Core', 'Kilkana'] as $paquete) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$paquete}/database/migrations",
            ])->run();
        }

        app(SnkKilkanaDatabaseSeeder::class)->run();
    }

    public function test_el_permiso_que_abre_el_panel_lo_tienen_los_seis_de_la_redaccion(): void
    {
        $puede = fn (string $rol) => Role::findByName($rol, 'sanctum')->hasPermissionTo('index posts');

        // Los seis que abren el panel. La tarjeta de plan se le enseña a toda la redacción por
        // decisión del usuario, así que la puerta del endpoint tiene que dejar pasar a los seis:
        // colgarla de `show settings` dejaría fuera a cuatro de ellos.
        foreach (['admin', 'director', 'editor', 'redactor', 'colaborador', 'moderador'] as $rol) {
            $this->assertTrue($puede($rol), "El rol {$rol} abre el panel y no puede listar piezas.");
        }
    }

    public function test_un_lector_no_pasa_de_la_puerta_aunque_tenga_sesion(): void
    {
        $lector = Role::findByName('reader', 'sanctum');

        // Un lector registrado existe para comentar, y eso es TODO lo que puede. Si algún día
        // gana `index posts`, esta prueba se pone roja antes de que lea la facturación del
        // periódico —plan, precio, asientos y banda— por `GET plan`.
        $this->assertFalse($lector->hasPermissionTo('index posts'));
        $this->assertSame(['create comments'], $lector->permissions->pluck('name')->all());
    }
}
