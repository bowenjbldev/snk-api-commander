<?php

namespace Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaActivityController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaAuthorsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaCategoriesController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaCommentsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaGateController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaNewsletterController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPanelController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPostsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaReadersController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaTagsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaTrashController;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Kilkana\Models\SnkKilkanaGateResultModel;
use Packages\Kilkana\Models\SnkKilkanaNewsletterIssueModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Kilkana\Services\SnkKilkanaCommentService;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * Las dos pantallas que el panel pide enteras.
 *
 * Lo que se fija aquí es lo único que puede romperse en silencio: **que cada sección del
 * compuesto sea exactamente lo que devuelve el endpoint al que sustituye**. Si alguna de las
 * dos formas se mueve sin la otra, el panel deja de leer una pantalla y nadie se entera hasta
 * que alguien la abre. Y que la sección que quien mira no puede ver llegue nula en vez de
 * tumbar la pantalla entera con un 403.
 */
class KilkanaPanelCompositeTest extends TestCase
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

    public function test_the_shell_matches_the_nine_calls_section_by_section(): void
    {
        $this->asDirector();
        $this->content();

        $panel = $this->body(app(SnkKilkanaPanelController::class)->bootstrap());

        $this->assertSame($this->standaloneBody(SnkKilkanaCategoriesController::class), $panel['categories']);
        $this->assertSame($this->standaloneBody(SnkKilkanaAuthorsController::class), $panel['authors']);
        $this->assertSame($this->standaloneBody(SnkKilkanaTagsController::class), $panel['tags']);
        $this->assertSame($this->standaloneBody(SnkKilkanaCommentsController::class, ['counts' => '1']), $panel['comments']);
        $this->assertSame($this->standaloneBody(SnkKilkanaReadersController::class, ['counts' => '1']), $panel['readers']);
        $this->assertSame($this->standaloneBody(SnkKilkanaActivityController::class, ['bell' => '6']), $panel['activity']);
        $this->assertSame($this->standaloneBody(SnkKilkanaTrashController::class, ['counts' => '1']), $panel['trash']);
        $this->assertSame($this->standaloneBody(SnkKilkanaGateController::class, ['counts' => '1']), $panel['gate']);
        $this->assertSame($this->standaloneBody(SnkKilkanaNewsletterController::class, ['counts' => '1']), $panel['newsletter']);
        $this->assertSame(Arr::only($this->posts('summary=counts'), ['counts', 'counts_all']), $panel['posts']);

        // Y trae algo: una comparación entre dos vacíos pasaría igual.
        $this->assertCount(1, $panel['categories']);
        $this->assertSame(1, $panel['comments']['counts']['pending']);
        $this->assertSame(1, $panel['gate']['failing']);
        $this->assertSame(1, $panel['newsletter']['drafts']);
        $this->assertSame(1, $panel['trash']['count']);
        $this->assertSame(3, array_sum($panel['posts']['counts']), 'La tirada no cuenta.');
        $this->assertNotSame([], $panel['activity']['data']);
    }

    /** Quien no puede ver una sección la recibe nula, y el resto de la pantalla llega igual. */
    public function test_what_cannot_be_seen_arrives_null_and_does_not_break_the_shell(): void
    {
        $this->content();
        $elena = CoreUserModel::create(['name' => 'Elena', 'email' => 'elena@eldiario.example', 'password' => 'secret1234']);
        $elena->assignRole('moderador');
        $this->actingAs($elena, 'sanctum');

        $panel = $this->body(app(SnkKilkanaPanelController::class)->bootstrap());

        // El moderador no tiene ninguno de los tres catálogos, y sin embargo tiene panel.
        $this->assertNull($panel['categories']);
        $this->assertNull($panel['authors']);
        $this->assertNull($panel['tags']);
        // El gate y la campana se leen con permisos de piezas, que el moderador sí tiene.
        $this->assertSame(1, $panel['gate']['failing']);
        $this->assertSame(1, $panel['comments']['counts']['pending'], 'Y lo suyo sí lo ve.');
        $this->assertSame(1, $panel['newsletter']['drafts']);
    }

    /**
     * Una cifra que se rompe llega nula, como una sin permiso, y el armazón se pinta igual.
     *
     * Las cifras llegan en la misma respuesta, así que la caída de una la tiene que parar la API.
     * Es la que ya costó una pantalla entera en «Something went wrong»: una columna que faltaba
     * en la cola de moderación.
     */
    public function test_a_failing_count_does_not_take_down_the_shell(): void
    {
        $this->asDirector();
        $this->content();
        $this->app->bind(SnkKilkanaCommentService::class, fn () => new class extends SnkKilkanaCommentService
        {
            public function countsByStatus(): array
            {
                throw new \RuntimeException('la columna que faltaba');
            }
        });

        $panel = $this->body(app(SnkKilkanaPanelController::class)->bootstrap());

        $this->assertNull($panel['comments'], 'La cifra rota deja su hueco.');
        $this->assertCount(1, $panel['categories'], 'Y el resto del armazón llega entero.');
        $this->assertSame(1, $panel['newsletter']['drafts']);
    }

    public function test_the_dashboard_matches_the_four_queue_calls(): void
    {
        $this->asDirector();
        $this->content();

        $dashboard = $this->body(app(SnkKilkanaPanelController::class)->dashboard());

        // Con `summary=counts`: el tablero pinta la barra de estados, así que la llamada
        // equivalente tiene que pedir las cifras. Sin ellas el listado ya no las devuelve.
        $this->assertSame($this->posts('status=review&sort=date&per_page=5&summary=counts'), $dashboard['review']);
        $this->assertSame($this->posts('status=scheduled&sort=date&per_page=5&summary=counts'), $dashboard['scheduled']);
        $this->assertSame($this->posts('status=published&per_page=5&summary=counts'), $dashboard['published']);
        $this->assertSame($this->posts('sort=-reads&per_page=6&summary=counts'), $dashboard['reads']);

        $this->assertSame(1, $dashboard['review']['meta']['total']);
        $this->assertSame(1, $dashboard['published']['meta']['total']);
        // Las cifras de la barra viajan con las cuatro y se calculan una vez.
        $this->assertSame($dashboard['review']['counts'], $dashboard['reads']['counts']);
    }

    /** La ventana de «lo más leído» es la del listado: siete, treinta, o desde siempre. */
    public function test_the_most_read_window_is_not_chosen_by_the_caller(): void
    {
        $this->asDirector();
        $this->content();

        foreach (['7' => 7, '30' => 30, '1000' => null, 'banana' => null] as $requested => $_) {
            request()->query->replace(['reads' => (string) $requested]);
            $dashboard = $this->body(app(SnkKilkanaPanelController::class)->dashboard());
            $this->assertArrayHasKey('data', $dashboard['reads'], "Con reads={$requested} la cola sigue existiendo.");
        }
    }

    /** @return array<string, mixed> */
    private function body(JsonResponse $response): array
    {
        return $response->getData(true);
    }

    /** El cuerpo del endpoint al que sustituye una sección. */
    private function standaloneBody(string $controller, array $query = []): mixed
    {
        request()->query->replace($query);

        return app($controller)->index()->getData(true);
    }

    /** Una de las cuatro colas del tablero, pedida como la pide su llamada suelta. */
    private function posts(string $query): array
    {
        parse_str($query, $params);
        request()->query->replace($params);

        return app(SnkKilkanaPostsController::class)->index()->getData(true);
    }

    private function asDirector(): CoreUserModel
    {
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');

        return $marta;
    }

    /** Una pieza de cada estado, un comentario pendiente, algo tirado y un veredicto que falla. */
    private function content(): void
    {
        $section = SnkKilkanaCategoryModel::create(['slug' => 'local', 'name' => 'Local', 'status' => 'live', 'position' => 0]);
        $byline = SnkKilkanaAuthorModel::create(['slug' => 'redaccion', 'alias' => 'Redacción']);
        SnkKilkanaTagModel::create(['slug' => 'clima', 'name' => 'Clima']);

        $common = ['category_id' => $section->id, 'author_id' => $byline->id, 'excerpt' => 'Entradilla.'];
        $published = SnkKilkanaPostModel::create([...$common, 'title' => 'Publicada', 'slug' => 'publicada', 'status' => 'published', 'publish_at' => now()->subHour()]);
        SnkKilkanaPostModel::create([...$common, 'title' => 'En revisión', 'slug' => 'en-revision', 'status' => 'review']);
        SnkKilkanaPostModel::create([...$common, 'title' => 'Programada', 'slug' => 'programada', 'status' => 'scheduled', 'publish_at' => now()->addDay()]);
        SnkKilkanaPostModel::create([...$common, 'title' => 'Tirada', 'slug' => 'tirada', 'status' => 'draft'])->delete();

        SnkKilkanaCommentModel::create(['post_id' => $published->id, 'body' => 'Hola', 'status' => 'pending']);
        SnkKilkanaNewsletterIssueModel::create(['subject' => 'Domingo', 'status' => SnkKilkanaNewsletterIssueModel::DRAFT]);
        SnkKilkanaGateResultModel::create([
            'url' => '/', 'kind' => 'front', 'checked_at' => now(),
            'passing' => array_fill(0, SnkKilkanaGateResultModel::CHECKS - 1, true) + [SnkKilkanaGateResultModel::CHECKS - 1 => false],
        ]);
    }
}
