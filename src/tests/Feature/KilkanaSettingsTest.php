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
        foreach (['Core', 'Kilkana'] as $package) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$package}/database/migrations",
            ])->run();
        }
        app(SnkKilkanaRoleCatalogService::class)->sync();
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('editor_in_chief');
        $this->actingAs($marta, 'sanctum');
    }

    public function test_what_is_written_shows_in_settings_and_on_the_street(): void
    {
        $defaults = app(SnkKilkanaSettingsController::class)->show()->getData(true);
        $this->assertSame('es-ES', $defaults['lang']);
        $this->assertSame('Europe/Madrid', $defaults['tz']);
        $this->assertTrue($defaults['search_visible']);
        $this->assertSame(['name' => null, 'email' => null], $defaults['legal']);

        $saved = $this->save([
            'tenant' => ['name' => 'El Diario', 'url' => 'https://demo.kilkana.test'],
            'lang' => 'en-GB',
            'tz' => 'Atlantic/Canary',
            'search_visible' => false,
            'newsletter_reply_to' => 'redaccion@eldiario.example',
            'legal' => ['name' => 'El Diario SL', 'email' => 'hola@eldiario.example', 'cif' => 'no entra'],
            'social' => ['x' => 'https://x.com/eldiario'],
        ])->getData(true);

        $this->assertSame('en-GB', $saved['lang']);
        $this->assertSame('Atlantic/Canary', $saved['tz']);
        $this->assertFalse($saved['search_visible']);
        $this->assertSame('redaccion@eldiario.example', $saved['newsletter_reply_to']);
        $this->assertSame(['name' => 'El Diario SL', 'email' => 'hola@eldiario.example'], $saved['legal'], 'Solo las claves del grupo, recortadas.');
        $this->assertSame(['x' => 'https://x.com/eldiario', 'facebook' => null, 'instagram' => null], $saved['social']);
        $this->assertSame(0, $saved['redirects']);
        $this->assertNotSame('', $saved['sender_address']);

        $street = app(SnkKilkanaPublicService::class)->site();
        $this->assertSame('en-GB', $street['lang']);
        $this->assertSame('Atlantic/Canary', $street['tz']);
        $this->assertFalse($street['search_visible']);
        $this->assertSame('El Diario SL', $street['legal']['name']);
        $this->assertSame('https://x.com/eldiario', $street['social']['x']);
        $this->assertSame('https://demo.kilkana.test', $street['tenant']['url']);

        // Un guardado parcial no borra lo demás.
        $other = $this->save(['tenant' => ['tagline' => 'Cada día']])->getData(true);
        $this->assertSame('en-GB', $other['lang']);
        $this->assertSame('El Diario SL', $other['legal']['name']);
    }

    /** Lanzar sella la fecha una vez; ni un segundo `true` la mueve ni un `false` la borra. */
    public function test_launching_happens_only_once(): void
    {
        $this->assertNull($this->save([])->getData(true)['launched_at']);
        $this->assertNull(app(SnkKilkanaPublicService::class)->site()['launched_at']);

        $first = $this->save(['launched' => true])->getData(true)['launched_at'];
        $this->assertNotNull($first);
        $this->assertSame($first, app(SnkKilkanaPublicService::class)->site()['launched_at']);

        $this->travel(1)->hours();
        $this->assertSame($first, $this->save(['launched' => true])->getData(true)['launched_at']);
        $this->assertSame($first, $this->save(['launched' => false])->getData(true)['launched_at']);
    }

    public function test_an_invented_timezone_or_language_is_rejected(): void
    {
        try {
            $this->save(['tz' => 'Europe/Alcorcon']);
            $this->fail('Una zona que no existe no es una zona.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tz', $e->errors());
        }
        try {
            $this->save(['lang' => 'fr-FR', 'social' => ['x' => 'javascript:alert(1)']]);
            $this->fail('Ni un idioma fuera de la lista ni una URL que no sea http.');
        } catch (ValidationException $e) {
            $this->assertSame(['lang', 'social.x'], array_keys($e->errors()));
        }
    }

    /** Reiniciar se lleva el archivo, las secciones y las etiquetas, y deja a la gente. */
    public function test_resetting_wipes_the_content_and_keeps_the_people(): void
    {
        $local = SnkKilkanaCategoryModel::create(['name' => 'Local', 'slug' => 'local', 'status' => 'live', 'position' => 0]);
        SnkKilkanaCategoryModel::create(['name' => 'Barrios', 'slug' => 'barrios', 'status' => 'live', 'position' => 1, 'parent_id' => $local->id]);
        $byline = SnkKilkanaAuthorModel::create(['alias' => 'Ada Prat']);
        $post = SnkKilkanaPostModel::create([
            'title' => 'Pleno', 'excerpt' => 'Entradilla.', 'slug' => 'pleno', 'status' => 'draft',
            'category_id' => $local->id, 'author_id' => $byline->id, 'created_by' => 1, 'updated_by' => 1,
        ]);
        $post->tags()->attach(SnkKilkanaTagModel::create(['name' => 'movilidad']));
        $this->save(['launched' => true]);

        $response = app(SnkKilkanaSettingsController::class)->reset();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($response->getData(true)['launched_at']);
        $this->assertSame(0, SnkKilkanaPostModel::withTrashed()->count());
        $this->assertSame(0, SnkKilkanaCategoryModel::count());
        $this->assertSame(0, SnkKilkanaTagModel::count());
        $this->assertSame(1, SnkKilkanaAuthorModel::count(), 'Las firmas son gente, no contenido.');
    }

    private function save(array $body)
    {
        return app(SnkKilkanaSettingsController::class)->update(Request::create('/', 'PUT', $body));
    }
}
