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
        foreach (['Core', 'Kilkana'] as $paquete) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$paquete}/database/migrations",
            ])->run();
        }

        app(SnkKilkanaRoleCatalogService::class)->sync();
    }

    public function test_una_pieza_cuenta_su_estado_y_quien_la_movio(): void
    {
        $marta = $this->cuenta('marta@eldiario.example', 'Marta Beneyto', 'director');
        $this->actingAs($marta, 'sanctum');

        $pieza = $this->pieza('Plan de aparcamientos', 'draft');
        $pieza->update(['status' => 'review']);
        $pieza->update(['status' => 'published', 'publish_at' => now()]);
        $pieza->update(['title' => 'Plan de aparcamientos en el centro']);
        $pieza->update(['status' => 'draft']);

        $verbos = SnkKilkanaActivityModel::query()->where('kind', '<>', 'category')->orderBy('id')->pluck('verb')->all();
        $this->assertSame(['created a draft', 'sent to review', 'published', 'edited', 'unpublished'], $verbos);
        $fila = SnkKilkanaActivityModel::query()->where('verb', 'published')->first();
        $this->assertSame('Marta Beneyto', $fila->actor_name);
        $this->assertSame('director', $fila->actor_role);
        $this->assertSame($marta->id, $fila->user_id);
        $this->assertSame('publish', $fila->kind);
    }

    /** Sin sesión firma la máquina: es lo que pasa cuando el programador publica a su hora. */
    public function test_sin_sesion_firma_el_programador_y_una_seccion_renombrada_guarda_el_antes(): void
    {
        $this->pieza('Plan de aparcamientos', 'published');
        $seccion = SnkKilkanaCategoryModel::firstOrCreate(['slug' => 'pueblos'], ['name' => 'Pueblos', 'status' => 'live', 'position' => 1]);
        $seccion->update(['name' => 'Municipios']);

        $maquina = SnkKilkanaActivityModel::query()->where('verb', 'created a draft')->orWhere('verb', 'published')->first();
        $this->assertNull($maquina->user_id);
        $this->assertSame(SnkKilkanaActivityModel::SYSTEM, $maquina->actor_name);

        $renombre = SnkKilkanaActivityModel::query()->where('kind', 'category')->where('verb', 'renamed the category')->first();
        $this->assertSame(['Pueblos', 'Municipios'], [$renombre->before, $renombre->after]);
    }

    public function test_un_lote_de_moderacion_se_apunta_como_lote_y_callar_calla(): void
    {
        $elena = $this->cuenta('elena@eldiario.example', 'Elena Vara', 'moderador');
        $this->actingAs($elena, 'sanctum');
        $pieza = app(SnkKilkanaActivityService::class)->quiet(fn () => $this->pieza('Plan de aparcamientos', 'published'));
        $this->assertSame(0, SnkKilkanaActivityModel::query()->count(), 'Callado no cuenta.');

        $lector = $this->cuenta('amparo@lectores.example', 'Amparo Ruiz', 'reader');
        $ids = collect(range(1, 3))->map(fn () => SnkKilkanaCommentModel::create(['post_id' => $pieza->id, 'user_id' => $lector->id, 'body' => 'Hola', 'status' => 'pending'])->id);
        request()->replace(['ids' => $ids->all(), 'action' => 'approve']);
        app(SnkKilkanaCommentsController::class)->bulk(request());

        $lote = SnkKilkanaActivityModel::query()->where('kind', 'comment')->get();
        $this->assertCount(1, $lote);
        $this->assertSame('approved 3 comments on', $lote[0]->verb);
        $this->assertSame('Plan de aparcamientos', $lote[0]->what);
    }

    /** Los movimientos de cuentas son de quien administra cuentas; la máquina no cuenta como persona. */
    public function test_el_registro_se_filtra_y_esconde_los_asientos_a_quien_no_los_administra(): void
    {
        $registro = app(SnkKilkanaActivityService::class);
        $marta = $this->cuenta('marta@eldiario.example', 'Marta Beneyto', 'director');
        $registro->log('seat', 'invited', 'paula@eldiario.example as Editor', '/roles', ['user' => $marta]);
        $registro->log('publish', 'published', 'Plan de aparcamientos', '/posts', ['user' => $marta]);
        $registro->log('publish', 'published', 'Fiestas de agosto', '/posts', ['user' => null]);
        $registro->log('theme', 'applied the theme', 'Folio', '/themes', ['user' => $marta, 'before' => 'Chronicle', 'after' => 'Folio']);

        $this->actingAs($this->cuenta('elena@eldiario.example', 'Elena Vara', 'moderador'), 'sanctum');
        $todo = $this->registro()->index()->getData(true);
        $this->assertSame(['theme', 'publish', 'publish'], array_column($todo['data'], 'kind'), 'Sin asientos, del más reciente al más antiguo.');
        $this->assertSame(['Marta Beneyto', 'Scheduler'], $todo['people']);
        $this->assertSame(['today' => 3, 'active_people' => 1, 'went_out' => 2, 'gate' => 0], $todo['stats']);
        $this->assertTrue($todo['data'][1]['system'] || $todo['data'][2]['system']);

        $contenido = $this->registro(['tab' => 'content', 'who' => 'Marta Beneyto'])->index()->getData(true);
        $this->assertSame(['Plan de aparcamientos'], array_column($contenido['data'], 'what'));

        // La campana pide un adelanto y recibe las filas y hasta cuándo se miró: ni gente ni cifras.
        $adelanto = $this->registro(['bell' => '2'])->index()->getData(true);
        $this->assertSame(['data', 'seen_at'], array_keys($adelanto));
        $this->assertCount(2, $adelanto['data']);

        // Por páginas: la segunda de a dos trae la tercera fila, con la cuenta entera al lado;
        // `all` se lleva lo filtrado sin partir, que es lo que pide un export.
        $segunda = $this->registro(['per_page' => '2', 'page' => '2'])->index()->getData(true);
        $this->assertSame(['Plan de aparcamientos'], array_column($segunda['data'], 'what'), 'La más antigua de las tres visibles.');
        $this->assertSame(['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2], $segunda['meta']);
        $this->assertSame(['data'], array_keys($this->registro(['all' => '1', 'per_page' => '1'])->index()->getData(true)));
        $this->assertCount(3, $this->registro(['all' => '1'])->index()->getData(true)['data']);

        // `index users` lo da el seeder de Core, no el catálogo: aquí se concede a mano.
        $admin = $this->cuenta('admin@eldiario.example', 'Admin', 'admin');
        $admin->givePermissionTo(Permission::findOrCreate('index users', 'sanctum'));
        $this->actingAs($admin, 'sanctum');
        $this->assertContains('seat', array_column($this->registro()->index()->getData(true)['data'], 'kind'));
    }

    /** La campana sabe hasta cuándo miró cada cuenta, y abrirla lo pone al día. */
    public function test_la_campana_lleva_hasta_cuando_se_miro_y_abrirla_lo_pone_al_dia(): void
    {
        $marta = $this->cuenta('marta@eldiario.example', 'Marta', 'director');
        $this->actingAs($marta, 'sanctum');
        $this->pieza('Una', 'published');

        $this->assertNull($this->registro(['bell' => 6])->index()->getData(true)['seen_at'], 'Nunca abierta.');

        $this->registro()->seen();
        $visto = $this->registro(['bell' => 6])->index()->getData(true)['seen_at'];
        $this->assertNotNull($visto);
        $this->assertEqualsWithDelta(now()->getTimestamp(), strtotime($visto), 2);
        $this->assertArrayNotHasKey('seen_at', $this->registro()->index()->getData(true), 'La pantalla entera no la lleva: es de la campana.');
    }

    /**
     * Con los agregados pedidos por defecto, que es lo que hace la pantalla: la API ya no los
     * manda solos. Quien quiera probar que no llegan, pasa `summary` vacío.
     */
    private function registro(array $query = []): SnkKilkanaActivityController
    {
        request()->query->replace(['summary' => 'people,stats', ...$query]);

        return app(SnkKilkanaActivityController::class);
    }

    private function cuenta(string $email, string $nombre, string $rol): CoreUserModel
    {
        $user = CoreUserModel::create(['name' => $nombre, 'email' => $email, 'password' => 'secret1234']);
        $user->assignRole($rol);

        return $user;
    }

    private function pieza(string $titulo, string $estado): SnkKilkanaPostModel
    {
        $seccion = SnkKilkanaCategoryModel::firstOrCreate(['slug' => 'local'], ['name' => 'Local', 'status' => 'live', 'position' => 0]);
        $firma = SnkKilkanaAuthorModel::firstOrCreate(['slug' => 'redaccion'], ['alias' => 'Redacción']);

        return SnkKilkanaPostModel::create([
            'title' => $titulo,
            'slug' => str($titulo)->slug()->value(),
            'url_date' => '2026-07-01',
            'category_id' => $seccion->id,
            'author_id' => $firma->id,
            'status' => $estado,
            'publish_at' => $estado === 'published' ? '2026-07-01 08:00:00' : null,
        ]);
    }
}
