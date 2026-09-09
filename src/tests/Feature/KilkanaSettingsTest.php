<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaSettingsController;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaTagModel;
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

    /** Lanzar sella la fecha una vez; ni un segundo `true` la mueve ni un `false` la borra. */
    public function test_lanzar_es_un_hecho_que_pasa_una_vez(): void
    {
        $this->assertNull($this->guardar([])->getData(true)['launched_at']);
        $this->assertNull(app(SnkKilkanaPublicService::class)->site()['launched_at']);

        $primera = $this->guardar(['launched' => true])->getData(true)['launched_at'];
        $this->assertNotNull($primera);
        $this->assertSame($primera, app(SnkKilkanaPublicService::class)->site()['launched_at']);

        $this->travel(1)->hours();
        $this->assertSame($primera, $this->guardar(['launched' => true])->getData(true)['launched_at']);
        $this->assertSame($primera, $this->guardar(['launched' => false])->getData(true)['launched_at']);
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

    /** Reiniciar se lleva el archivo, las secciones y las etiquetas, y deja a la gente. */
    public function test_la_puesta_en_marcha_se_reinicia_entera_y_deja_a_la_gente(): void
    {
        $local = SnkKilkanaCategoryModel::create(['name' => 'Local', 'slug' => 'local', 'status' => 'live', 'position' => 0]);
        SnkKilkanaCategoryModel::create(['name' => 'Barrios', 'slug' => 'barrios', 'status' => 'live', 'position' => 1, 'parent_id' => $local->id]);
        $firma = SnkKilkanaAuthorModel::create(['alias' => 'Ada Prat']);
        $pieza = SnkKilkanaPostModel::create([
            'title' => 'Pleno', 'excerpt' => 'Entradilla.', 'slug' => 'pleno', 'status' => 'draft',
            'category_id' => $local->id, 'author_id' => $firma->id, 'created_by' => 1, 'updated_by' => 1,
        ]);
        $pieza->tags()->attach(SnkKilkanaTagModel::create(['name' => 'movilidad']));
        $this->guardar(['launched' => true]);

        $respuesta = app(SnkKilkanaSettingsController::class)->reset();

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertNull($respuesta->getData(true)['launched_at']);
        $this->assertSame(0, SnkKilkanaPostModel::withTrashed()->count());
        $this->assertSame(0, SnkKilkanaCategoryModel::count());
        $this->assertSame(0, SnkKilkanaTagModel::count());
        $this->assertSame(1, SnkKilkanaAuthorModel::count(), 'Las firmas son gente, no contenido.');
    }

    private function guardar(array $cuerpo)
    {
        return app(SnkKilkanaSettingsController::class)->update(Request::create('/', 'PUT', $cuerpo));
    }
}
