<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaSettingsController;
use Packages\Kilkana\Services\SnkKilkanaPublicService;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * Los ajustes del periódico: lo que la redacción escribe llega a la calle, un grupo abierto
 * solo guarda sus claves, y una zona horaria o un idioma inventados no entran.
 */
class KilkanaSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::setDefaultConnection('tenant');
        foreach (['Core', 'Kilkana'] as $paquete) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$paquete}/database/migrations",
            ])->run();
        }
        app(SnkKilkanaRoleCatalogService::class)->sync();
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
    }

    public function test_lo_que_se_escribe_sale_en_los_ajustes_y_en_la_calle(): void
    {
        $sin = app(SnkKilkanaSettingsController::class)->show()->getData(true);
        $this->assertSame('es-ES', $sin['lang']);
        $this->assertSame('Europe/Madrid', $sin['tz']);
        $this->assertTrue($sin['search_visible']);
        $this->assertSame(['name' => null, 'email' => null], $sin['legal']);

        $con = $this->guardar([
            'tenant' => ['name' => 'El Diario', 'url' => 'https://demo.kilkana.test'],
            'lang' => 'ca-ES',
            'tz' => 'Atlantic/Canary',
            'search_visible' => false,
            'newsletter_reply_to' => 'redaccion@eldiario.example',
            'legal' => ['name' => 'El Diario SL', 'email' => 'hola@eldiario.example', 'cif' => 'no entra'],
            'social' => ['x' => 'https://x.com/eldiario'],
        ])->getData(true);

        $this->assertSame('ca-ES', $con['lang']);
        $this->assertSame('Atlantic/Canary', $con['tz']);
        $this->assertFalse($con['search_visible']);
        $this->assertSame('redaccion@eldiario.example', $con['newsletter_reply_to']);
        $this->assertSame(['name' => 'El Diario SL', 'email' => 'hola@eldiario.example'], $con['legal'], 'Solo las claves del grupo, recortadas.');
        $this->assertSame(['x' => 'https://x.com/eldiario', 'facebook' => null, 'instagram' => null], $con['social']);
        $this->assertSame(0, $con['redirects']);
        $this->assertNotSame('', $con['sender_address']);

        $calle = app(SnkKilkanaPublicService::class)->site();
        $this->assertSame('ca-ES', $calle['lang']);
        $this->assertSame('Atlantic/Canary', $calle['tz']);
        $this->assertFalse($calle['search_visible']);
        $this->assertSame('El Diario SL', $calle['legal']['name']);
        $this->assertSame('https://x.com/eldiario', $calle['social']['x']);
        $this->assertSame('https://demo.kilkana.test', $calle['tenant']['url']);

        // Un guardado parcial no borra lo demás.
        $otra = $this->guardar(['tenant' => ['tagline' => 'Cada día']])->getData(true);
        $this->assertSame('ca-ES', $otra['lang']);
        $this->assertSame('El Diario SL', $otra['legal']['name']);
    }

    public function test_una_zona_o_un_idioma_inventados_no_entran(): void
    {
        try {
            $this->guardar(['tz' => 'Europe/Alcorcon']);
            $this->fail('Una zona que no existe no es una zona.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tz', $e->errors());
        }
        try {
            $this->guardar(['lang' => 'fr-FR', 'social' => ['x' => 'javascript:alert(1)']]);
            $this->fail('Ni un idioma fuera de la lista ni una URL que no sea http.');
        } catch (ValidationException $e) {
            $this->assertSame(['lang', 'social.x'], array_keys($e->errors()));
        }
    }

    private function guardar(array $cuerpo)
    {
        return app(SnkKilkanaSettingsController::class)->update(Request::create('/', 'PUT', $cuerpo));
    }
}
