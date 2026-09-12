<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaActivityController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaCommentsController;
use Packages\Kilkana\Models\SnkKilkanaActivityModel;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Services\SnkKilkanaActivityService;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * El registro es una línea de tiempo que el panel escribe al escribir. Lo que se fija aquí:
 * que una pieza cuenta su estado con el verbo de una redacción, que quien firma es quien
 * estaba en la sesión y sin sesión firma la máquina, que un lote se apunta como lote y no
 * como veinte filas, que callar calla, y que los movimientos de cuentas solo los ve quien
 * administra cuentas.
 */
class KilkanaActivityTest extends TestCase
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

    public function test_a_post_records_its_status_and_who_moved_it(): void
    {
        $marta = $this->account('marta@eldiario.example', 'Marta Beneyto', 'director');
        $this->actingAs($marta, 'sanctum');

        $post = $this->makePost('Plan de aparcamientos', 'draft');
        $post->update(['status' => 'review']);
        $post->update(['status' => 'published', 'publish_at' => now()]);
        $post->update(['title' => 'Plan de aparcamientos en el centro']);
        $post->update(['status' => 'draft']);

        $verbs = SnkKilkanaActivityModel::query()->where('kind', '<>', 'category')->orderBy('id')->pluck('verb')->all();
        $this->assertSame(['draft_created', 'sent_to_review', 'published', 'edited', 'unpublished'], $verbs);
        $row = SnkKilkanaActivityModel::query()->where('verb', 'published')->first();
        $this->assertSame('Marta Beneyto', $row->actor_name);
        $this->assertSame('director', $row->actor_role);
        $this->assertSame($marta->id, $row->user_id);
        $this->assertSame('publish', $row->kind);
    }

    /** Sin sesión firma la máquina: es lo que pasa cuando el programador publica a su hora. */
    public function test_without_a_session_the_scheduler_signs_and_a_renamed_section_keeps_its_previous_name(): void
    {
        $this->makePost('Plan de aparcamientos', 'published');
        $section = SnkKilkanaCategoryModel::firstOrCreate(['slug' => 'pueblos'], ['name' => 'Pueblos', 'status' => 'live', 'position' => 1]);
        $section->update(['name' => 'Municipios']);

        $automatic = SnkKilkanaActivityModel::query()->where('verb', 'draft_created')->orWhere('verb', 'published')->first();
        $this->assertNull($automatic->user_id);
        $this->assertSame(SnkKilkanaActivityModel::SYSTEM, $automatic->actor_name);

        $rename = SnkKilkanaActivityModel::query()->where('kind', 'category')->where('verb', 'category_renamed')->first();
        $this->assertSame(['Pueblos', 'Municipios'], [$rename->before, $rename->after]);
    }

    public function test_a_moderation_batch_is_logged_as_a_batch_and_quiet_mode_stays_quiet(): void
    {
        $elena = $this->account('elena@eldiario.example', 'Elena Vara', 'moderador');
        $this->actingAs($elena, 'sanctum');
        $post = app(SnkKilkanaActivityService::class)->quiet(fn () => $this->makePost('Plan de aparcamientos', 'published'));
        $this->assertSame(0, SnkKilkanaActivityModel::query()->count(), 'Callado no cuenta.');

        $reader = $this->account('amparo@lectores.example', 'Amparo Ruiz', 'reader');
        $ids = collect(range(1, 3))->map(fn () => SnkKilkanaCommentModel::create(['post_id' => $post->id, 'user_id' => $reader->id, 'body' => 'Hola', 'status' => 'pending'])->id);
        request()->replace(['ids' => $ids->all(), 'action' => 'approve']);
        app(SnkKilkanaCommentsController::class)->bulk(request());

        $batch = SnkKilkanaActivityModel::query()->where('kind', 'comment')->get();
        $this->assertCount(1, $batch);
        $this->assertSame('comments_approved', $batch[0]->verb);
        $this->assertSame('Plan de aparcamientos', $batch[0]->what);
        $this->assertSame(['count' => 3], $batch[0]->params, 'La cifra va aparte: la frase la pone el panel.');
    }

    /**
     * Las filas escritas con frases pasan a clave, con las cifras y los nombres fuera de la frase.
     * Es la tabla que usa la migración: una fila que ya es clave no se toca.
     */
    public function test_legacy_sentences_become_keys_with_their_params(): void
    {
        $this->assertSame(['verb' => 'draft_created', 'what' => 'Plan', 'params' => null], SnkKilkanaActivityService::fromLegacy('created a draft', 'Plan'));
        $this->assertSame(['verb' => 'comments_spammed', 'what' => '', 'params' => ['count' => 3, 'posts' => 2]], SnkKilkanaActivityService::fromLegacy('marked as spam 3 comments on', '2 posts'));
        $this->assertSame(['verb' => 'comments_approved', 'what' => 'Plan', 'params' => ['count' => 1]], SnkKilkanaActivityService::fromLegacy('approved 1 comment on', 'Plan'));
        $this->assertSame(['verb' => 'deploy_blocked', 'what' => '', 'params' => ['failing' => 3, 'total' => 40]], SnkKilkanaActivityService::fromLegacy('blocked the deploy:', '3 of 40 routes fail'));
        $this->assertSame(['verb' => 'seat_invited', 'what' => 'paula@eldiario.example', 'params' => ['role' => 'editor']], SnkKilkanaActivityService::fromLegacy('invited', 'paula@eldiario.example as Editor'));
        $this->assertSame(['verb' => 'tags_merged', 'what' => 'movilidad', 'params' => null], SnkKilkanaActivityService::fromLegacy('merged the tags', 'movilidad-urbana into movilidad', 'movilidad'));
        $this->assertSame(['verb' => 'front_published', 'what' => '', 'params' => ['layout' => 'section']], SnkKilkanaActivityService::fromLegacy('published the front page', 'Section'));
        $this->assertSame(['verb' => 'uploaded', 'what' => '', 'params' => null], SnkKilkanaActivityService::fromLegacy('uploaded', 'a file'));
        $this->assertNull(SnkKilkanaActivityService::fromLegacy('published_elsewhere', 'Plan'), 'Lo que no es una frase heredada no se toca.');
    }

    /** Los movimientos de cuentas son de quien administra cuentas; la máquina no cuenta como persona. */
    public function test_the_log_filters_and_hides_seats_from_whoever_does_not_manage_them(): void
    {
        $activityLog = app(SnkKilkanaActivityService::class);
        $marta = $this->account('marta@eldiario.example', 'Marta Beneyto', 'director');
        $activityLog->log('seat', 'seat_invited', 'paula@eldiario.example', '/roles', ['user' => $marta, 'params' => ['role' => 'editor']]);
        $activityLog->log('publish', 'published', 'Plan de aparcamientos', '/posts', ['user' => $marta]);
        $activityLog->log('publish', 'published', 'Fiestas de agosto', '/posts', ['user' => null]);
        $activityLog->log('theme', 'theme_applied', 'Folio', '/themes', ['user' => $marta, 'before' => 'Chronicle', 'after' => 'Folio']);

        $this->actingAs($this->account('elena@eldiario.example', 'Elena Vara', 'moderador'), 'sanctum');
        $all = $this->activityController()->index()->getData(true);
        $this->assertSame(['theme', 'publish', 'publish'], array_column($all['data'], 'kind'), 'Sin asientos, del más reciente al más antiguo.');
        $this->assertSame(['Marta Beneyto', 'Scheduler'], $all['people']);
        // Cincuenta por página, no los veinticinco del resto: son líneas de una línea, y el
        // defecto lo declara este controlador sobre el clamp del controlador base.
        $this->assertSame(50, $all['meta']['per_page']);
        $this->assertSame(['today' => 3, 'active_people' => 1, 'went_out' => 2, 'gate' => 0], $all['stats']);
        $this->assertTrue($all['data'][1]['system'] || $all['data'][2]['system']);

        $content = $this->activityController(['tab' => 'content', 'who' => 'Marta Beneyto'])->index()->getData(true);
        $this->assertSame(['Plan de aparcamientos'], array_column($content['data'], 'what'));

        // La campana pide un adelanto y recibe las filas y hasta cuándo se miró: ni gente ni cifras.
        $bell = $this->activityController(['bell' => '2'])->index()->getData(true);
        $this->assertSame(['data', 'seen_at'], array_keys($bell));
        $this->assertCount(2, $bell['data']);

        // Por páginas: la segunda de a dos trae la tercera fila, con la cuenta entera al lado;
        // `all` se lleva lo filtrado sin partir, que es lo que pide un export.
        $secondPage = $this->activityController(['per_page' => '2', 'page' => '2'])->index()->getData(true);
        $this->assertSame(['Plan de aparcamientos'], array_column($secondPage['data'], 'what'), 'La más antigua de las tres visibles.');
        $this->assertSame(['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2], $secondPage['meta']);
        $this->assertSame(['data'], array_keys($this->activityController(['all' => '1', 'per_page' => '1'])->index()->getData(true)));
        $this->assertCount(3, $this->activityController(['all' => '1'])->index()->getData(true)['data']);

        // `index users` lo da el seeder de Core, no el catálogo: aquí se concede a mano.
        $admin = $this->account('admin@eldiario.example', 'Admin', 'admin');
        $admin->givePermissionTo(Permission::findOrCreate('index users', 'sanctum'));
        $this->actingAs($admin, 'sanctum');
        $this->assertContains('seat', array_column($this->activityController()->index()->getData(true)['data'], 'kind'));
    }

    /** La campana sabe hasta cuándo miró cada cuenta, y abrirla lo pone al día. */
    public function test_the_bell_counts_since_last_seen_and_opening_it_catches_up(): void
    {
        $marta = $this->account('marta@eldiario.example', 'Marta', 'director');
        $this->actingAs($marta, 'sanctum');
        $this->makePost('Una', 'published');

        $this->assertNull($this->activityController(['bell' => 6])->index()->getData(true)['seen_at'], 'Nunca abierta.');

        $this->activityController()->seen();
        $seenAt = $this->activityController(['bell' => 6])->index()->getData(true)['seen_at'];
        $this->assertNotNull($seenAt);
        $this->assertEqualsWithDelta(now()->getTimestamp(), strtotime($seenAt), 2);
        $this->assertArrayNotHasKey('seen_at', $this->activityController()->index()->getData(true), 'La pantalla entera no la lleva: es de la campana.');
    }

    /**
     * Con los agregados pedidos por defecto, que es lo que hace la pantalla: la API no los
     * manda si no se piden. Quien quiera probar que no llegan, pasa `summary` vacío.
     */
    private function activityController(array $query = []): SnkKilkanaActivityController
    {
        request()->query->replace(['summary' => 'people,stats', ...$query]);

        return app(SnkKilkanaActivityController::class);
    }

    private function account(string $email, string $name, string $role): CoreUserModel
    {
        $user = CoreUserModel::create(['name' => $name, 'email' => $email, 'password' => 'secret1234']);
        $user->assignRole($role);

        return $user;
    }

    private function makePost(string $title, string $status): SnkKilkanaPostModel
    {
        $section = SnkKilkanaCategoryModel::firstOrCreate(['slug' => 'local'], ['name' => 'Local', 'status' => 'live', 'position' => 0]);
        $byline = SnkKilkanaAuthorModel::firstOrCreate(['slug' => 'redaccion'], ['alias' => 'Redacción']);

        return SnkKilkanaPostModel::create([
            'title' => $title,
            'slug' => str($title)->slug()->value(),
            'url_date' => '2026-07-01',
            'category_id' => $section->id,
            'author_id' => $byline->id,
            'status' => $status,
            'publish_at' => $status === 'published' ? '2026-07-01 08:00:00' : null,
        ]);
    }
}
