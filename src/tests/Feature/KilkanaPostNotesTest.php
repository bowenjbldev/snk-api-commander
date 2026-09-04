<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPostNotesController;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaPostNoteModel;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/** Las notas de mesa: se escriben sobre una pieza o un bloque, se resuelven, y se van con la pieza. */
class KilkanaPostNotesTest extends TestCase
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
        DB::statement('PRAGMA foreign_keys = ON');
        app(SnkKilkanaRoleCatalogService::class)->sync();
    }

    public function test_una_nota_se_escribe_sobre_un_bloque_se_resuelve_y_se_va_con_la_pieza(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta Beneyto', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
        $seccion = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $firma = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        $pieza = SnkKilkanaPostModel::create(['title' => 'Plan', 'slug' => 'plan', 'category_id' => $seccion->id, 'author_id' => $firma->id, 'status' => 'draft', 'body_blocks' => [], 'created_by' => $marta->id, 'updated_by' => $marta->id]);
        $notas = app(SnkKilkanaPostNotesController::class);

        $creada = $notas->add(Request::create('/', 'POST', ['body' => '¿Cifra confirmada?', 'block' => 'b2']), $pieza->id);
        $this->assertSame(201, $creada->getStatusCode());
        $this->assertSame('Marta Beneyto', $creada->getData(true)['user']['name']);
        $this->assertSame(404, $notas->add(Request::create('/', 'POST', ['body' => 'x']), 999)->getStatusCode());

        $lista = $notas->list($pieza->id)->getData(true);
        $this->assertSame(['b2'], array_column($lista, 'block'));
        $this->assertFalse($lista[0]['done']);

        $id = $lista[0]['id'];
        $this->assertTrue($notas->resolve(Request::create('/', 'PATCH', ['done' => true]), $pieza->id, $id)->getData(true)['done']);
        $this->assertSame(404, $notas->resolve(Request::create('/', 'PATCH', ['done' => true]), $pieza->id + 1, $id)->getStatusCode(), 'Una nota no se toca desde otra pieza.');

        $pieza->forceDelete();
        $this->assertSame(0, SnkKilkanaPostNoteModel::query()->count());
    }
}
