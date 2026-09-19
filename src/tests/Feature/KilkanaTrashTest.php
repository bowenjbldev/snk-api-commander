<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaTrashController;
use Packages\Kilkana\Models\SnkKilkanaActivityModel;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Services\SnkKilkanaPublicService;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Packages\Kilkana\Services\SnkKilkanaTrashService;
use Tests\TestCase;

/**
 * La papelera es una cuenta atrás con la URL reservada. Lo que se fija: que una pieza tirada
 * desaparece del panel y de la calle pero su URL no se puede reutilizar, que restaurarla la
 * devuelve como borrador con la misma URL, que un medio tirado dice qué piezas publicadas lo
 * llevan, que purgar y vaciar se van de verdad y la purga nocturna respeta el plazo, y que
 * cada clase entra con el permiso de borrarla.
 */
class KilkanaTrashTest extends TestCase
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
        // SQLite no aplica las claves foráneas si no se le pide: purgar un medio deja la
        // portada a null por `nullOnDelete`, y sin esto la columna se queda apuntando a nada.
        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function test_a_trashed_post_keeps_its_url_and_returns_as_draft(): void
    {
        $marta = $this->account('marta@eldiario.example', 'Marta Beneyto', 'editor_in_chief');
        $this->actingAs($marta, 'sanctum');
        $post = $this->makePost('Plan de aparcamientos');
        $path = $post->url_path;

        $post->delete();

        $this->assertNull(SnkKilkanaPostModel::query()->find($post->id), 'El panel no la ve.');
        $this->assertNull(app(SnkKilkanaPublicService::class)->post('2026', '07', '01', 'plan-de-aparcamientos'), 'La calle tampoco.');
        $this->assertSame($marta->id, SnkKilkanaPostModel::withTrashed()->find($post->id)->deleted_by);
        $this->assertTrue(SnkKilkanaPostModel::pathTaken('2026-07-01', 'plan-de-aparcamientos'), 'La URL sigue reservada.');

        $listing = $this->trash()->index()->getData(true);
        $this->assertSame(['post'], array_column($listing['data'], 'kind'));
        $this->assertEquals(['who' => 'Marta Beneyto', 'days_left' => 30, 'path' => '/'.$path], array_intersect_key($listing['data'][0], ['who' => 1, 'days_left' => 1, 'path' => 1]));

        $this->assertSame(200, $this->trash()->restore('post', $post->id)->getStatusCode());
        $restored = SnkKilkanaPostModel::query()->find($post->id);
        $this->assertSame('draft', $restored->status->value, 'Vuelve como borrador, no publicada.');
        $this->assertSame($path, $restored->url_path, 'Con la misma URL.');
        $this->assertNull($restored->deleted_by);
        $this->assertSame(['deleted', 'restored_from_trash'], SnkKilkanaActivityModel::query()->where('kind', '<>', 'category')->where('kind', '<>', 'edit')->orderBy('id')->pluck('verb')->all());
    }

    public function test_trashed_media_lists_the_posts_using_it_and_purging_removes_it(): void
    {
        $this->actingAs($this->account('marta@eldiario.example', 'Marta Beneyto', 'editor_in_chief'), 'sanctum');
        $photo = SnkKilkanaMediaModel::create(['url' => 'https://cdn.example/a.jpg', 'url_hash' => sha1('a'), 'title' => 'Plaza mayor', 'width' => 1600, 'height' => 900, 'size_bytes' => 120000]);
        $coveredPost = $this->makePost('Plan de aparcamientos', ['cover_media_id' => $photo->id]);
        $this->makePost('Fiestas de agosto', ['body_blocks' => [['type' => 'image', 'config' => ['media_id' => $photo->id]]]]);
        $this->makePost('Un borrador', ['status' => 'draft', 'cover_media_id' => $photo->id]);
        $photo->delete();

        $row = $this->trash()->index()->getData(true)['data'][0];
        $this->assertSame('media', $row['kind']);
        $this->assertSame('1600 × 900', $row['path']);
        $this->assertSame(['Fiestas de agosto', 'Plan de aparcamientos', 'Un borrador'], collect($row['refs'])->sort()->values()->all(), 'Un borrador pierde la foto igual: cuenta.');

        $this->assertSame(200, $this->trash()->purge('media', $photo->id)->getStatusCode());
        $this->assertNull(SnkKilkanaMediaModel::withTrashed()->find($photo->id));
        $this->assertNull($coveredPost->fresh()->cover_media_id, 'La portada se queda sin foto: eso es lo que purgar rompe.');
        $this->assertSame(404, $this->trash()->purge('media', $photo->id)->getStatusCode());
    }

    public function test_emptying_purges_and_the_nightly_purge_respects_the_retention(): void
    {
        $this->actingAs($this->account('marta@eldiario.example', 'Marta Beneyto', 'editor_in_chief'), 'sanctum');
        $old = $this->makePost('Vieja');
        $recent = $this->makePost('Nueva');
        $old->delete();
        $recent->delete();
        SnkKilkanaPostModel::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDays(31)]);

        $this->assertSame(1, app(SnkKilkanaTrashService::class)->purgeExpired());
        $this->assertNull(SnkKilkanaPostModel::withTrashed()->find($old->id));
        $this->assertNotNull(SnkKilkanaPostModel::withTrashed()->find($recent->id), 'La reciente sigue esperando.');

        $this->assertSame(['purged' => 1, 'kinds' => ['post', 'media']], $this->trash()->empty()->getData(true));
        $this->assertSame(0, SnkKilkanaPostModel::withTrashed()->count());
    }

    /** Cada clase entra con el permiso de borrarla: la mesa de edición ve piezas y medios; la moderación, nada. */
    public function test_each_kind_requires_the_permission_to_delete_it(): void
    {
        $editorInChief = $this->account('marta@eldiario.example', 'Marta Beneyto', 'editor_in_chief');
        $this->actingAs($editorInChief, 'sanctum');
        $this->makePost('Plan de aparcamientos')->delete();

        $this->actingAs($this->account('elena@eldiario.example', 'Elena Vara', 'moderator'), 'sanctum');
        $this->assertSame(403, $this->trash()->index()->getStatusCode());
        $this->assertSame(403, $this->trash()->empty()->getStatusCode());

        $this->actingAs($this->account('ruben@eldiario.example', 'Rubén Nieto', 'contributor'), 'sanctum');
        $this->assertSame(403, $this->trash()->index()->getStatusCode());

        $this->actingAs($editorInChief, 'sanctum');
        $this->assertSame(404, $this->trash()->restore('banana', 1)->getStatusCode());
        $this->assertSame(1, $this->trash(['counts' => '1'])->index()->getData(true)['count']);
    }

    private function trash(array $query = []): SnkKilkanaTrashController
    {
        request()->query->replace($query);

        return app(SnkKilkanaTrashController::class);
    }

    private function account(string $email, string $name, string $role): CoreUserModel
    {
        $user = CoreUserModel::create(['name' => $name, 'email' => $email, 'password' => 'secret1234']);
        $user->assignRole($role);

        return $user;
    }

    private function makePost(string $title, array $extra = []): SnkKilkanaPostModel
    {
        $section = SnkKilkanaCategoryModel::firstOrCreate(['slug' => 'local'], ['name' => 'Local', 'status' => 'live', 'position' => 0]);
        $byline = SnkKilkanaAuthorModel::firstOrCreate(['slug' => 'redaccion'], ['alias' => 'Redacción']);

        return SnkKilkanaPostModel::create([
            'title' => $title,
            'slug' => str($title)->slug()->value(),
            'url_date' => '2026-07-01',
            'category_id' => $section->id,
            'author_id' => $byline->id,
            'status' => 'published',
            'publish_at' => '2026-07-01 08:00:00',
            'excerpt' => 'Una entradilla.',
            ...$extra,
        ]);
    }
}
