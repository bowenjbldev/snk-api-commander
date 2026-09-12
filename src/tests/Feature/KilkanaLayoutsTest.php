<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaLayoutsController;
use Packages\Kilkana\Services\SnkKilkanaPublicService;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * La composición de la portada, tal y como la escribe el panel: se publica con la versión
 * leída, quien llega con una vieja recibe la actual y no pisa nada, cada publicación deja
 * una revisión que se puede restaurar, y la calle lee lo publicado sin sesión.
 */
class KilkanaLayoutsTest extends TestCase
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
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
    }

    public function test_publishing_keeps_versions_does_not_overwrite_and_can_be_restored(): void
    {
        $controller = app(SnkKilkanaLayoutsController::class);
        $first = $controller->show('front')->getData(true);
        $this->assertSame(1, $first['version']);
        $this->assertSame([], $first['schema']['sections']);

        $one = ['version' => 1, 'sections' => [['id' => 'home', 'bands' => [['id' => 'b1', 'split' => 'full', 'cols' => [[]]]]]]];
        $published = $controller->update(Request::create('/', 'PUT', ['schema' => $one, 'version' => 1]), 'front');
        $this->assertSame(200, $published->getStatusCode());
        $this->assertSame(2, $published->getData(true)['version']);

        // Quien leyó la versión 1 y publica ahora no pisa: recibe lo que hay.
        $two = ['version' => 1, 'sections' => [['id' => 'home', 'bands' => []]]];
        $late = $controller->update(Request::create('/', 'PUT', ['schema' => $two, 'version' => 1]), 'front');
        $this->assertSame(409, $late->getStatusCode());
        $this->assertSame('version_conflict', $late->getData(true)['error']);
        $this->assertSame(2, $late->getData(true)['current']['version']);
        $this->assertSame('b1', $controller->show('front')->getData(true)['schema']['sections'][0]['bands'][0]['id']);

        // Con la versión buena entra, y deja la anterior en el historial.
        $controller->update(Request::create('/', 'PUT', ['schema' => $two, 'version' => 2]), 'front');
        $revisions = $controller->revisions('front')->getData(true);
        $this->assertCount(2, $revisions);
        $this->assertSame('Marta', $revisions[0]['user']['name']);

        // Restaurar la que tenía la banda: vuelve con versión nueva y una revisión más.
        $withBand = collect($revisions)->first(fn (array $r) => ($r['schema']['sections'][0]['bands'] ?? []) !== []);
        $restored = $controller->restore('front', $withBand['id'])->getData(true);
        $this->assertSame(4, $restored['version']);
        $this->assertSame('b1', $restored['schema']['sections'][0]['bands'][0]['id']);
        $this->assertCount(3, $controller->revisions('front')->getData(true));

        // La calle lee lo publicado, sin versión y sin sesión.
        auth()->forgetGuards();
        $street = app(SnkKilkanaPublicService::class)->layout('front');
        $this->assertSame('b1', $street['schema']['sections'][0]['bands'][0]['id']);
        $this->assertArrayNotHasKey('version', $street);
    }
}
