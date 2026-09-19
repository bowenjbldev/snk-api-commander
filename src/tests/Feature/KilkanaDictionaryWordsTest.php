<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaDictionaryWordsController;
use Packages\Kilkana\Models\SnkKilkanaDictionaryWordModel;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * El diccionario de la redacción: lo amplía quien escribe y lo recorta la mesa.
 *
 * Es el mismo reparto que las etiquetas, y por lo mismo: un apellido se añade tecleando, pero
 * quitar una palabra vuelve a marcarla como errata en la pantalla de todos.
 */
class KilkanaDictionaryWordsTest extends TestCase
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

    public function test_a_reporter_adds_a_word_and_adding_it_again_is_not_an_error(): void
    {
        $controller = $this->as('reporter');

        $first = $controller->store(Request::create('/', 'POST', ['word' => 'Kilkana']));
        $again = $controller->store(Request::create('/', 'POST', ['word' => 'Kilkana']));

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(200, $again->getStatusCode(), 'Dos redactores con el mismo apellido no son un fallo.');
        $this->assertSame(1, SnkKilkanaDictionaryWordModel::query()->count());
    }

    public function test_upper_and_lower_case_are_two_words(): void
    {
        $controller = $this->as('reporter');

        $controller->store(Request::create('/', 'POST', ['word' => 'Roma']));
        $controller->store(Request::create('/', 'POST', ['word' => 'roma']));

        $this->assertSame(2, SnkKilkanaDictionaryWordModel::query()->count());
    }

    public function test_what_is_not_a_single_word_is_rejected(): void
    {
        $controller = $this->as('reporter');

        foreach (['dos palabras', 'COVID-19', '<b>x</b>', ''] as $word) {
            $response = $controller->store(Request::create('/', 'POST', ['word' => $word]));
            $this->assertSame(422, $response->getStatusCode(), "«{$word}» no es una palabra.");
        }
    }

    public function test_a_reporter_cannot_remove_a_word_and_an_editor_can(): void
    {
        $word = SnkKilkanaDictionaryWordModel::create(['word' => 'Kilkana']);

        $this->assertSame(403, $this->as('reporter')->destroy($word->id)->getStatusCode());
        $this->assertNotNull(SnkKilkanaDictionaryWordModel::query()->find($word->id), 'Y sigue ahí.');

        $this->as('editor')->destroy($word->id);
        $this->assertNull(SnkKilkanaDictionaryWordModel::query()->find($word->id));
    }

    public function test_a_moderator_does_not_write_and_does_not_see_the_dictionary(): void
    {
        $this->assertSame(403, $this->as('moderator')->index()->getStatusCode());
    }

    private function as(string $role): SnkKilkanaDictionaryWordsController
    {
        $user = CoreUserModel::firstOrCreate(
            ['email' => "{$role}@eldiario.example"],
            ['name' => ucfirst($role), 'password' => 'secret1234']
        );
        $user->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return new SnkKilkanaDictionaryWordsController;
    }
}
