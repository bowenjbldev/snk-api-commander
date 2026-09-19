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
        foreach (['Core', 'Kilkana'] as $package) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$package}/database/migrations",
            ])->run();
        }
        DB::statement('PRAGMA foreign_keys = ON');
        app(SnkKilkanaRoleCatalogService::class)->sync();
    }

    public function test_a_note_is_written_on_a_block_resolved_and_removed_with_the_post(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta Beneyto', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('editor_in_chief');
        $this->actingAs($marta, 'sanctum');
        $section = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $byline = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        $post = SnkKilkanaPostModel::create(['title' => 'Plan', 'slug' => 'plan', 'category_id' => $section->id, 'author_id' => $byline->id, 'status' => 'draft', 'body_blocks' => [], 'created_by' => $marta->id, 'updated_by' => $marta->id]);
        $notes = app(SnkKilkanaPostNotesController::class);

        $created = $notes->add(Request::create('/', 'POST', ['body' => '¿Cifra confirmada?', 'block' => 'b2']), $post->id);
        $this->assertSame(201, $created->getStatusCode());
        $this->assertSame('Marta Beneyto', $created->getData(true)['user']['name']);
        $this->assertSame(404, $notes->add(Request::create('/', 'POST', ['body' => 'x']), 999)->getStatusCode());

        $listed = $notes->list($post->id)->getData(true);
        $this->assertSame(['b2'], array_column($listed, 'block'));
        $this->assertFalse($listed[0]['done']);

        $id = $listed[0]['id'];
        $this->assertTrue($notes->resolve(Request::create('/', 'PATCH', ['done' => true]), $post->id, $id)->getData(true)['done']);
        $this->assertSame(404, $notes->resolve(Request::create('/', 'PATCH', ['done' => true]), $post->id + 1, $id)->getStatusCode(), 'Una nota no se toca desde otra pieza.');

        $post->forceDelete();
        $this->assertSame(0, SnkKilkanaPostNoteModel::query()->count());
    }
}
