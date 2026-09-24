<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPostsController;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaMediaModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Services\SnkKilkanaPostService;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * La portada de una pieza es un medio de la mediateca, y guardar una pieza no inventa medios:
 * el id se comprueba contra la tabla, y lo que no existe se rechaza en vez de acuñarse.
 */
class KilkanaPostCoverTest extends TestCase
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

    public function test_the_cover_is_set_by_id_cleared_with_null_and_a_fake_id_creates_nothing(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta Beneyto', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('editor_in_chief');
        $this->actingAs($marta, 'sanctum');

        $section = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $byline = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        $photo = SnkKilkanaMediaModel::create(['url' => '/storage/a.jpg', 'url_hash' => sha1('a'), 'alt' => 'La plaza']);
        $post = SnkKilkanaPostModel::create([
            'title' => 'Plan', 'slug' => 'plan', 'category_id' => $section->id, 'author_id' => $byline->id,
            'status' => 'draft', 'body_blocks' => [], 'created_by' => $marta->id, 'updated_by' => $marta->id,
        ]);
        $save = fn (array $body) => app(SnkKilkanaPostsController::class)->update(Request::create('/', 'PUT', $body), $post->id);

        $this->assertSame(200, $save(['cover_media_id' => $photo->id])->getStatusCode());
        $this->assertSame($photo->id, $post->fresh()->cover_media_id);

        $response = $save(['cover_media_id' => $photo->id + 1]);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('cover_media_id', $response->getData(true));
        $this->assertSame($photo->id, $post->fresh()->cover_media_id, 'Un id falso no toca la pieza.');
        $this->assertSame(1, SnkKilkanaMediaModel::query()->count(), 'Y no acuña ningún medio.');

        $this->assertSame(200, $save(['cover_media_id' => null])->getStatusCode());
        $this->assertNull($post->fresh()->cover_media_id, 'Vacío quita la foto.');
    }

    /** El alt y el pie de la foto en esta pieza se guardan tal cual; nulos, heredan los de la ficha. */
    public function test_the_cover_and_image_blocks_keep_their_own_alt_and_caption(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta Beneyto', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('editor_in_chief');
        $this->actingAs($marta, 'sanctum');
        $section = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $byline = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        $photo = SnkKilkanaMediaModel::create(['url' => '/storage/a.jpg', 'url_hash' => sha1('a'), 'alt' => 'La plaza']);
        $post = SnkKilkanaPostModel::create(['title' => 'Plan', 'slug' => 'plan', 'category_id' => $section->id, 'author_id' => $byline->id, 'status' => 'draft', 'body_blocks' => [], 'created_by' => $marta->id, 'updated_by' => $marta->id]);
        $save = fn (array $body) => app(SnkKilkanaPostsController::class)->update(Request::create('/', 'PUT', $body), $post->id);

        $image = ['id' => 'b1', 'type' => 'image', 'config' => ['media_id' => $photo->id, 'alt' => 'La plaza, vacía al amanecer', 'caption' => 'Antes de la feria.']];
        $this->assertSame(200, $save([
            'cover_media_id' => $photo->id,
            'cover_alt' => 'La plaza llena de puestos',
            'cover_caption' => 'El primer día de la feria.',
            'body_blocks' => [$image],
        ])->getStatusCode());
        $fresh = $post->fresh();
        $this->assertSame('La plaza llena de puestos', $fresh->cover_alt);
        $this->assertSame('El primer día de la feria.', $fresh->cover_caption);
        $this->assertSame('La plaza, vacía al amanecer', $fresh->body_blocks[0]['config']['alt']);
        $this->assertSame('La plaza', $photo->fresh()->alt, 'La ficha de la mediateca no se toca.');

        $long = str_repeat('a', 501);
        $this->assertSame(422, $save(['cover_alt' => $long])->getStatusCode());
        $image['config']['alt'] = $long;
        $this->assertSame(422, $save(['body_blocks' => [$image]])->getStatusCode(), 'El alt de un bloque tiene el mismo tope.');

        $this->assertSame(200, $save(['cover_alt' => null, 'cover_caption' => null])->getStatusCode());
        $this->assertNull($post->fresh()->cover_alt, 'Vacío vuelve a heredar.');
    }

    /** Quien guarda desde una versión vieja no pisa la nueva: se le dice quién y cuándo. */
    public function test_saving_from_a_stale_version_returns_who_wrote_over_it(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta Beneyto', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('editor_in_chief');
        $this->actingAs($marta, 'sanctum');
        $section = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $byline = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        $post = SnkKilkanaPostModel::create(['title' => 'Plan', 'slug' => 'plan', 'category_id' => $section->id, 'author_id' => $byline->id, 'status' => 'draft', 'body_blocks' => [], 'created_by' => $marta->id, 'updated_by' => $marta->id]);
        $stale = $post->updated_at->toIso8601String();
        $save = fn (array $body) => app(SnkKilkanaPostsController::class)->update(Request::create('/', 'PUT', $body), $post->id);

        $this->travel(1)->minute();
        $this->assertSame(200, $save(['title' => 'Plan B', 'expected_updated_at' => $stale])->getStatusCode(), 'La primera escritura desde esa versión entra.');

        $late = $save(['title' => 'Plan C', 'expected_updated_at' => $stale]);
        $this->assertSame(409, $late->getStatusCode());
        $this->assertSame('Marta Beneyto', $late->getData(true)['updated_by']);
        $this->assertSame('Plan B', $post->fresh()->title, 'Y no pisa.');
        $this->assertSame(200, $save(['title' => 'Plan D'])->getStatusCode(), 'Sin versión declarada, como siempre.');

        // El mismo instante en otra variante ISO —milisegundos y Z— es la misma versión.
        $now = $post->fresh()->updated_at->copy()->utc()->format('Y-m-d\\TH:i:s.000\\Z');
        $this->assertSame(200, $save(['title' => 'Plan E', 'expected_updated_at' => $now])->getStatusCode());
        $this->assertSame(409, $save(['title' => 'Plan F', 'expected_updated_at' => 'no es una fecha'])->getStatusCode(), 'Lo que no parsea no es una versión.');
    }

    /** La retirada programada: lo publicado con hora cumplida vuelve a borrador, con la URL reservada y sin la hora. */
    public function test_a_post_with_a_takedown_time_returns_to_draft_when_due(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta Beneyto', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('editor_in_chief');
        $this->actingAs($marta, 'sanctum');
        $section = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $byline = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        $post = SnkKilkanaPostModel::create(['title' => 'Oferta', 'slug' => 'oferta', 'url_date' => '2026-09-05', 'category_id' => $section->id, 'author_id' => $byline->id, 'status' => 'published', 'publish_at' => now()->subHour(), 'excerpt' => 'E.', 'body_blocks' => [], 'created_by' => $marta->id, 'updated_by' => $marta->id]);
        $save = fn (array $body) => app(SnkKilkanaPostsController::class)->update(Request::create('/', 'PUT', $body), $post->id);

        $past = $save(['unpublish_at' => now()->subMinute()->toIso8601String()]);
        $this->assertSame(422, $past->getStatusCode(), 'Una hora pasada no es programar una retirada.');
        $this->assertArrayHasKey('unpublish_at', $past->getData(true));
        $this->assertSame(200, $save(['unpublish_at' => now()->addHour()->toIso8601String()])->getStatusCode());

        // Devuelta a borrador, la retirada futura se queda —publicar y retirar se programan
        // juntas—; la que ya pasó se borra, porque si se quedara cada guardado devolvería 422
        // por un campo que nadie tocó.
        $this->assertSame(200, $save(['status' => 'draft'])->getStatusCode());
        $this->assertNotNull($post->fresh()->unpublish_at, 'Una retirada futura sobrevive al borrador.');
        // Y a la programada, que es el caso de verdad: publicar el viernes y retirar el domingo.
        $scheduled = $save(['status' => 'scheduled', 'publish_at' => now()->addDay()->toIso8601String(), 'unpublish_at' => now()->addDays(3)->toIso8601String()]);
        $this->assertSame(200, $scheduled->getStatusCode());
        $this->assertSame(200, $save(['title' => 'Oferta del fin de semana'])->getStatusCode());
        $this->assertNotNull($post->fresh()->unpublish_at, 'Programar publicación y retirada juntas sobrevive a un guardado.');
        $this->assertSame(200, $save(['status' => 'draft', 'unpublish_at' => now()->addHour()->toIso8601String()])->getStatusCode());
        $this->travel(2)->hours();
        $this->assertSame(200, $save(['title' => 'Oferta corregida'])->getStatusCode(), 'Un guardado ajeno a la hora no se bloquea.');
        $this->assertNull($post->fresh()->unpublish_at, 'Una retirada pasada en un borrador no significa nada.');
        $this->travelBack();
        $this->assertSame(200, $save(['status' => 'published', 'unpublish_at' => now()->addHour()->toIso8601String()])->getStatusCode());
        $service = app(SnkKilkanaPostService::class);

        $this->assertSame(0, $service->unpublishDue(), 'Todavía no es la hora.');
        $this->travel(2)->hours();
        $this->assertSame(1, $service->unpublishDue());
        $reverted = $post->fresh();
        $this->assertSame('draft', $reverted->status->value);
        $this->assertNull($reverted->unpublish_at, 'La hora se borra: volver a publicarla no la retira otra vez.');
        $this->assertSame('2026/09/05/oferta', $reverted->url_path, 'La URL sigue reservada.');
        $this->assertSame(0, $service->unpublishDue(), 'Y no se retira dos veces.');
    }
}
