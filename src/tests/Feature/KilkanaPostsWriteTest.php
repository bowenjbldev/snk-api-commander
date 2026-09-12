<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPostsController;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaPostRevisionModel;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * Lo que se escribe por la puerta de artículos: alta, guardado, lote e historial.
 *
 * Existe porque **seis de las ocho acciones de este controlador no las ejecutaba ninguna
 * prueba**: el alta entera, el lote, el historial, la restauración, el muro de publicación en
 * sus tres puertas y el 404 de lo ajeno. Todo eso se puede romper sin que la suite se entere,
 * y lo que viene después es mover ese código de sitio.
 *
 * La forma del 422 es contrato y aquí se fija: **el objeto de errores va pelado, en la raíz**.
 * Envolverlo en `{message, errors}` —que es lo que hace un `FormRequest` al lanzar— cambiaría
 * lo que el panel parsea sin que ninguna otra prueba lo notase.
 */
class KilkanaPostsWriteTest extends TestCase
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
        $this->seedWorld();
    }

    public function test_creating_returns_the_post_and_validation_errors_come_at_the_root(): void
    {
        $this->asRole('director');

        $creation = $this->controller()->store($this->makeRequest(['title' => 'El pleno aprueba el presupuesto']));

        $this->assertSame(201, $creation->getStatusCode());
        $this->assertSame('El pleno aprueba el presupuesto', $creation->getData(true)['title']);

        $broken = $this->controller()->store($this->makeRequest(['title' => '', 'category' => 'no-existe']));

        $this->assertSame(422, $broken->getStatusCode());
        $body = $broken->getData(true);
        $this->assertArrayHasKey('title', $body, 'Los errores van en la raíz, no anidados.');
        $this->assertArrayHasKey('category', $body);
        $this->assertArrayNotHasKey('errors', $body, 'Ni envueltos: el panel lee la raíz.');
        $this->assertArrayNotHasKey('message', $body);
    }

    public function test_without_a_chosen_byline_the_creator_signs(): void
    {
        $writer = $this->asRole('redactor');

        $creation = $this->controller()->store($this->makeRequest(['title' => 'Sin firma elegida', 'author_id' => null]));

        $this->assertSame(201, $creation->getStatusCode());
        $byline = SnkKilkanaAuthorModel::query()->where('user_id', $writer->id)->first();
        $this->assertNotNull($byline, 'La cuenta que crea tiene firma, aunque no la tuviera antes.');
        $this->assertSame($byline->id, $creation->getData(true)['author_id']);
    }

    /** El muro entre escribir y publicar, en sus tres puertas. */
    public function test_publishing_is_not_decided_by_the_writer(): void
    {
        $writer = $this->asRole('redactor');
        $own = $this->makePost('Lo mío', $writer->id);
        $version = SnkKilkanaPostRevisionModel::create([
            'post_id' => $own->id,
            'user_id' => $writer->id,
            'payload' => ['title' => 'Lo mío', 'status' => 'published'],
        ]);

        $creation = $this->controller()->store($this->makeRequest(['title' => 'Sale ya', 'status' => 'published', 'excerpt' => 'E.', 'publish_at' => '2026-07-01 10:00:00']));
        $saved = $this->controller()->update($this->makeRequest(['status' => 'published']), $own->id);
        $restored = $this->controller()->restore($own->id, $version->id);

        foreach (['alta' => $creation, 'guardado' => $saved, 'restauración' => $restored] as $gate => $response) {
            $this->assertSame(403, $response->getStatusCode(), "La {$gate} tiene que parar sin `publish posts`.");
            $this->assertSame('publish posts', $response->getData(true)['permission'], "Y decir cuál falta ({$gate}).");
        }
    }

    /**
     * Publicar es AHORA, y por eso la fecha no se le pide a nadie.
     *
     * Un borrador no tiene `publish_at` —se sella al salir—, así que validar solo el cuerpo
     * hacía que `required_if` rechazase: desde el editor no se podía publicar una pieza.
     */
    public function test_publishing_a_draft_needs_neither_a_date_nor_an_excerpt(): void
    {
        $marta = $this->asRole('director');
        $draft = $this->makePost('Sin fecha', $marta->id);

        $saved = $this->controller()->update($this->makeRequest(['status' => 'published']), $draft->id);

        $this->assertSame(200, $saved->getStatusCode(), $saved->getContent());
        $this->assertNotNull($draft->fresh()->publish_at, 'Se sella al salir.');
        $this->assertSame('published', $draft->fresh()->status->value);
    }

    /** Una pieza ajena no existe: 404, y no 403, que confirmaría cuántas hay. */
    public function test_someone_elses_post_does_not_exist_for_whoever_cannot_touch_it(): void
    {
        $marta = $this->asRole('director');
        $own = $this->makePost('De la jefa', $marta->id);
        $this->asRole('redactor');

        $this->assertSame(404, $this->controller()->update($this->makeRequest(['title' => 'Mía ahora']), $own->id)->getStatusCode());
        $this->assertSame(404, $this->controller()->revisions($own->id)->getStatusCode());
        $this->assertSame('De la jefa', $own->fresh()->title, 'Y no la toca.');
    }

    public function test_the_batch_requires_the_permission_for_its_action_and_reports_how_many_moved(): void
    {
        $writer = $this->asRole('redactor');
        $one = $this->makePost('Una', $writer->id);
        $other = $this->makePost('Otra', $writer->id);
        $ids = [$one->id, $other->id];

        $moved = $this->controller()->bulk(Request::create('/', 'POST', ['ids' => $ids, 'action' => 'review']));

        $this->assertSame(['ok' => true, 'count' => 2], $moved->getData(true));
        $this->assertSame('review', $one->fresh()->status->value);

        $publish = $this->controller()->bulk(Request::create('/', 'POST', ['ids' => $ids, 'action' => 'publish']));

        $this->assertSame(403, $publish->getStatusCode(), 'Publicar en tanda es publicar.');
        $this->assertSame('publish posts', $publish->getData(true)['permission']);
    }

    /** Eliminar en tanda es la papelera; programar en tanda no existe, porque un lote no trae fecha. */
    public function test_the_batch_sends_to_the_trash_and_does_not_schedule(): void
    {
        $marta = $this->asRole('director');
        $one = $this->makePost('Una', $marta->id);

        $deleted = $this->controller()->bulk(Request::create('/', 'POST', ['ids' => [$one->id], 'action' => 'delete']));

        $this->assertSame(['ok' => true, 'count' => 1], $deleted->getData(true));
        $this->assertSoftDeleted($one);

        $this->expectException(ValidationException::class);
        $this->controller()->bulk(Request::create('/', 'POST', ['ids' => [$one->id], 'action' => 'scheduled']));
    }

    public function test_revisions_are_listed_per_post_and_restoring_one_brings_back_its_title(): void
    {
        $marta = $this->asRole('director');
        $post = $this->makePost('Titular viejo', $marta->id);
        $this->controller()->update($this->makeRequest(['title' => 'Titular nuevo']), $post->id);

        $history = $this->controller()->revisions($post->id)->getData(true);

        $this->assertCount(1, $history, 'Guardar deja una versión.');
        $this->assertSame(404, $this->controller()->revisions($post->id + 99)->getStatusCode());

        $reverted = $this->controller()->restore($post->id, $history[0]['id']);

        $this->assertSame(200, $reverted->getStatusCode(), $reverted->getContent());
        $this->assertSame('Titular viejo', $post->fresh()->title, 'Restaurar devuelve el titular de esa versión.');
        $this->assertSame(1, DB::table('kilkana_activity')->where('verb', 'version_restored')->count());
    }

    private function controller(): SnkKilkanaPostsController
    {
        return app(SnkKilkanaPostsController::class);
    }

    /** El cuerpo mínimo que el alta acepta, con lo que se le quiera cambiar encima. */
    private function makeRequest(array $changes = []): Request
    {
        return Request::create('/', 'PUT', [
            'title' => 'Un titular',
            'category' => 'local',
            'author_id' => SnkKilkanaAuthorModel::query()->toBase()->value('id'),
            'status' => 'draft',
            ...$changes,
        ]);
    }

    private function asRole(string $role): CoreUserModel
    {
        $account = CoreUserModel::create([
            'name' => ucfirst($role),
            'email' => "{$role}@eldiario.example",
            'password' => 'secret1234',
        ]);
        $account->assignRole($role);
        $this->actingAs($account, 'sanctum');

        return $account;
    }

    private function makePost(string $headline, int $authorId): SnkKilkanaPostModel
    {
        return SnkKilkanaPostModel::create([
            'title' => $headline,
            'excerpt' => 'Entradilla.',
            'category_id' => SnkKilkanaCategoryModel::query()->value('id'),
            'author_id' => SnkKilkanaAuthorModel::query()->toBase()->value('id'),
            'status' => 'draft',
            'created_by' => $authorId,
            'updated_by' => $authorId,
        ]);
    }

    private function seedWorld(): void
    {
        SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
    }
}
