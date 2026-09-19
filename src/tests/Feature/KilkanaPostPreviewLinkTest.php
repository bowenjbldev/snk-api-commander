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
        foreach (['Core', 'Kilkana'] as $package) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$package}/database/migrations",
            ])->run();
        }
        app(SnkKilkanaRoleCatalogService::class)->sync();
    }

    public function test_the_link_opens_the_draft_and_a_tampered_or_expired_signature_does_not(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('editor_in_chief');
        $this->actingAs($marta, 'sanctum');
        $section = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $byline = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        $post = SnkKilkanaPostModel::create(['title' => 'Todavía no', 'slug' => 'todavia-no', 'category_id' => $section->id, 'author_id' => $byline->id, 'status' => 'draft', 'excerpt' => 'E.', 'body_blocks' => [['id' => 'b1', 'type' => 'paragraph', 'config' => ['text' => 'Secreto']]]]);

        $link = app(SnkKilkanaPostsController::class)->previewLink($post->id)->getData(true);
        $this->assertStringStartsWith("/preview/{$post->id}?until=", $link['path']);
        parse_str((string) parse_url($link['path'], PHP_URL_QUERY), $query);

        auth()->forgetGuards();
        $open = fn (array $q) => app(SnkKilkanaPublicController::class)->preview(Request::create('/', 'GET', $q), $post->id);

        $preview = $open($query);
        $this->assertSame(200, $preview->getStatusCode());
        $this->assertSame('Todavía no', $preview->getData(true)['title']);
        $this->assertSame('Secreto', $preview->getData(true)['body_blocks'][0]['config']['text']);

        $this->assertSame(404, $open(['until' => $query['until'], 'sig' => strrev($query['sig'])])->getStatusCode(), 'Una firma tocada no abre nada.');
        $this->assertSame(404, $open(['until' => (string) ((int) $query['until'] + 1), 'sig' => $query['sig']])->getStatusCode(), 'Y alargar la caducidad rompe la firma.');
        $this->travel(8)->days();
        $this->assertSame(404, $open($query)->getStatusCode(), 'A la semana caduca.');
    }
}
