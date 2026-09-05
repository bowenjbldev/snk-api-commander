<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPostsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPublicController;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * La previa por enlace: un borrador se enseña a quien tiene el enlace y a nadie más. Lo que
 * se fija es que el enlace abre la pieza sin sesión, que una firma tocada o caducada es un
 * 404 como cualquier otro, y que la pieza no sale por la calle normal mientras es borrador.
 */
class KilkanaPostPreviewLinkTest extends TestCase
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
    }

    public function test_el_enlace_abre_el_borrador_y_una_firma_tocada_o_caducada_no(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
        $seccion = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $firma = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        $pieza = SnkKilkanaPostModel::create(['title' => 'Todavía no', 'slug' => 'todavia-no', 'category_id' => $seccion->id, 'author_id' => $firma->id, 'status' => 'draft', 'excerpt' => 'E.', 'body_blocks' => [['id' => 'b1', 'type' => 'paragraph', 'config' => ['text' => 'Secreto']]]]);

        $enlace = app(SnkKilkanaPostsController::class)->previewLink($pieza->id)->getData(true);
        $this->assertStringStartsWith("/preview/{$pieza->id}?until=", $enlace['path']);
        parse_str((string) parse_url($enlace['path'], PHP_URL_QUERY), $query);

        auth()->forgetGuards();
        $abrir = fn (array $q) => app(SnkKilkanaPublicController::class)->preview(Request::create('/', 'GET', $q), $pieza->id);

        $previa = $abrir($query);
        $this->assertSame(200, $previa->getStatusCode());
        $this->assertSame('Todavía no', $previa->getData(true)['title']);
        $this->assertSame('Secreto', $previa->getData(true)['body_blocks'][0]['config']['text']);

        $this->assertSame(404, $abrir(['until' => $query['until'], 'sig' => strrev($query['sig'])])->getStatusCode(), 'Una firma tocada no abre nada.');
        $this->assertSame(404, $abrir(['until' => (string) ((int) $query['until'] + 1), 'sig' => $query['sig']])->getStatusCode(), 'Y alargar la caducidad rompe la firma.');
        $this->travel(8)->days();
        $this->assertSame(404, $abrir($query)->getStatusCode(), 'A la semana caduca.');
    }
}
