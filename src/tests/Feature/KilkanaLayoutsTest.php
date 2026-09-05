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

    public function test_publicar_versiona_no_pisa_y_se_restaura(): void
    {
        $controlador = app(SnkKilkanaLayoutsController::class);
        $primera = $controlador->show('front')->getData(true);
        $this->assertSame(1, $primera['version']);
        $this->assertSame([], $primera['schema']['sections']);

        $uno = ['version' => 1, 'sections' => [['id' => 'home', 'bands' => [['id' => 'b1', 'split' => 'full', 'cols' => [[]]]]]]];
        $publicada = $controlador->update(Request::create('/', 'PUT', ['schema' => $uno, 'version' => 1]), 'front');
        $this->assertSame(200, $publicada->getStatusCode());
        $this->assertSame(2, $publicada->getData(true)['version']);

        // Quien leyó la versión 1 y publica ahora no pisa: recibe lo que hay.
        $dos = ['version' => 1, 'sections' => [['id' => 'home', 'bands' => []]]];
        $tarde = $controlador->update(Request::create('/', 'PUT', ['schema' => $dos, 'version' => 1]), 'front');
        $this->assertSame(409, $tarde->getStatusCode());
        $this->assertSame('version_conflict', $tarde->getData(true)['error']);
        $this->assertSame(2, $tarde->getData(true)['current']['version']);
        $this->assertSame('b1', $controlador->show('front')->getData(true)['schema']['sections'][0]['bands'][0]['id']);

        // Con la versión buena entra, y deja la anterior en el historial.
        $controlador->update(Request::create('/', 'PUT', ['schema' => $dos, 'version' => 2]), 'front');
        $revisiones = $controlador->revisions('front')->getData(true);
        $this->assertCount(2, $revisiones);
        $this->assertSame('Marta', $revisiones[0]['user']['name']);

        // Restaurar la que tenía la banda: vuelve con versión nueva y una revisión más.
        $conBanda = collect($revisiones)->first(fn (array $r) => ($r['schema']['sections'][0]['bands'] ?? []) !== []);
        $restaurada = $controlador->restore('front', $conBanda['id'])->getData(true);
        $this->assertSame(4, $restaurada['version']);
        $this->assertSame('b1', $restaurada['schema']['sections'][0]['bands'][0]['id']);
        $this->assertCount(3, $controlador->revisions('front')->getData(true));

        // La calle lee lo publicado, sin versión y sin sesión.
        auth()->forgetGuards();
        $calle = app(SnkKilkanaPublicService::class)->layout('front');
        $this->assertSame('b1', $calle['schema']['sections'][0]['bands'][0]['id']);
        $this->assertArrayNotHasKey('version', $calle);
    }
}
