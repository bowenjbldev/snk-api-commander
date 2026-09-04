<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Enums\SnkKilkanaContentTierEnum;
use Packages\Kilkana\Http\Controllers\SnkKilkanaAuthController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaCommentsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaReadersController;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaReaderReadModel;
use Packages\Kilkana\Models\SnkKilkanaSubscriberModel;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * Un lector es una cuenta, no un perfil: se lista, se cruza con el boletín y con lo que ha
 * escrito y leído, y se bloquea. Lo que se fija aquí es que la redacción no sale en esa lista,
 * que las pestañas son filtros y no estados, que bloquear cierra y se deshace, y que una
 * lectura medida solo se apunta a quien viene con su cuenta y solo sobre una pieza con muro.
 */
class KilkanaReadersTest extends TestCase
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

    public function test_la_lista_es_de_lectores_y_cada_fila_trae_boletin_comentarios_y_lecturas(): void
    {
        $this->comoModerador();
        $amparo = $this->lector('amparo@lectores.example', 'Amparo Ruiz');
        $joaquin = $this->lector('joaquin@lectores.example', 'Joaquín Vidal');
        $pieza = $this->pieza('Plan de aparcamientos', SnkKilkanaContentTierEnum::Metered);
        $this->comentario($amparo, $pieza, 'Las plazas ya estaban saturadas.', 'approved');
        $this->comentario($amparo, $pieza, 'Insulto que no sale.', 'rejected');
        SnkKilkanaSubscriberModel::create(['email' => 'amparo@lectores.example', 'status' => 'confirmed', 'consent_source' => 'public_form']);
        SnkKilkanaReaderReadModel::record($amparo->id, $pieza);

        $lista = $this->lectores()->index()->getData(true);

        $this->assertSame(2, $lista['meta']['total'], 'La moderadora no es una lectora.');
        $fila = collect($lista['data'])->firstWhere('id', (string) $amparo->id);
        $this->assertSame('Amparo Ruiz', $fila['name']);
        $this->assertSame(['comments' => 2, 'rejected' => 1, 'reads' => 1, 'blocked' => false], array_intersect_key($fila, ['comments' => 1, 'rejected' => 1, 'reads' => 1, 'blocked' => 1]));
        $this->assertSame('confirmed', $fila['newsletter']['status']);
        $this->assertSame('public_form', $fila['newsletter']['consent_source']);
        $this->assertNotNull($fila['last_seen_at']);
        $this->assertNull(collect($lista['data'])->firstWhere('id', (string) $joaquin->id)['newsletter']);
        $this->assertSame(['all' => 2, 'subscribers' => 1, 'commenters' => 1, 'blocked' => 0], $lista['counts']);
        $this->assertSame(['accounts' => 2, 'confirmed_subscribers' => 1, 'commented_this_month' => 1, 'blocked' => 0], $lista['stats']);
    }

    /** Las pestañas son tres preguntas sobre la misma persona, y un bloqueado no es un comentarista. */
    public function test_las_pestanas_filtran_y_la_busqueda_mira_nombre_y_correo(): void
    {
        $this->comoModerador();
        $amparo = $this->lector('amparo@lectores.example', 'Amparo Ruiz');
        $ramon = $this->lector('rsempere@lectores.example', 'Ramón Sempere');
        $pieza = $this->pieza('Plan de aparcamientos');
        $this->comentario($amparo, $pieza, 'Las plazas ya estaban saturadas.', 'approved');
        $this->comentario($ramon, $pieza, 'Publicidad.', 'spam');
        $this->comentario($this->lector('u9902@correo-gratis.example', 'u9902'), $pieza, 'Compra aqui.', 'spam');
        $ramon->forceFill(['suspended_at' => now()])->save();

        $comentan = $this->lectores(['tab' => 'commenters'])->index()->getData(true);
        $this->assertSame([(string) $amparo->id], array_column($comentan['data'], 'id'), 'Solo spam no es comentar.');

        $bloqueados = $this->lectores(['tab' => 'blocked'])->index()->getData(true);
        $this->assertSame([(string) $ramon->id], array_column($bloqueados['data'], 'id'));
        $this->assertTrue($bloqueados['data'][0]['blocked']);

        $porCorreo = $this->lectores(['q' => 'rsempere'])->index()->getData(true);
        $this->assertSame([(string) $ramon->id], array_column($porCorreo['data'], 'id'));

        $solo = $this->lectores(['counts' => '1'])->index()->getData(true);
        $this->assertSame(['accounts' => 3], $solo);
    }

    public function test_bloquear_cierra_la_cuenta_y_vacia_su_cola_y_desbloquear_la_reabre(): void
    {
        $this->comoModerador();
        $ramon = $this->lector('rsempere@lectores.example', 'Ramón Sempere');
        $ramon->createToken('calle');
        $pieza = $this->pieza('Plan de aparcamientos');
        $this->comentario($ramon, $pieza, 'Pendiente.', 'pending');
        $this->comentario($ramon, $pieza, 'Ya aprobado.', 'approved');

        $bloqueo = $this->lectores()->block($ramon->id);
        $this->assertSame(200, $bloqueo->getStatusCode());
        $this->assertSame(1, $bloqueo->getData(true)['rejected']);
        $ramon->refresh();
        $this->assertNotNull($ramon->suspended_at);
        $this->assertSame(0, $ramon->tokens()->count());
        $this->assertSame(1, SnkKilkanaCommentModel::query()->where('user_id', $ramon->id)->where('status', 'rejected')->count());
        $this->assertSame(1, SnkKilkanaCommentModel::query()->where('user_id', $ramon->id)->where('status', 'approved')->count(), 'Lo aprobado se queda.');

        $this->assertSame(200, $this->lectores()->unblock($ramon->id)->getStatusCode());
        $this->assertNull($ramon->refresh()->suspended_at);
        $this->assertSame(1, SnkKilkanaCommentModel::query()->where('user_id', $ramon->id)->where('status', 'rejected')->count(), 'Desbloquear no deshace una decisión.');
    }

    /** A un compañero no se le bloquea desde aquí: la lista no lo ve ni con su id a mano. */
    public function test_la_redaccion_no_se_bloquea_desde_lectores(): void
    {
        $elena = $this->comoModerador();

        $this->assertSame(404, $this->lectores()->block($elena->id)->getStatusCode());
        $this->assertNull($elena->refresh()->suspended_at);
    }

    /** Sin `update readers` no se bloquea, ni desde aquí ni desde la moderación. */
    public function test_bloquear_es_de_quien_gobierna_lectores(): void
    {
        $editor = CoreUserModel::create(['name' => 'Iván', 'email' => 'ivan@eldiario.example', 'password' => 'secret1234']);
        $editor->assignRole('editor');
        $this->actingAs($editor, 'sanctum');
        $ramon = $this->lector('rsempere@lectores.example', 'Ramón Sempere');

        $this->assertSame(403, $this->lectores()->index()->getStatusCode());
        $this->assertSame(403, $this->lectores()->block($ramon->id)->getStatusCode());
    }

    /**
     * Una lectura medida se apunta una vez por mes y solo sobre una pieza con muro. Quién la
     * apunta lo decide `recordView` con el token que viaje; eso no se prueba aquí porque el
     * contador diario va en SQL de MariaDB y SQLite no lo ejecuta.
     */
    public function test_una_lectura_medida_cuenta_una_vez_al_mes_y_una_gratuita_no_cuenta(): void
    {
        $amparo = $this->lector('amparo@lectores.example', 'Amparo Ruiz');
        $medida = $this->pieza('Plan de aparcamientos', SnkKilkanaContentTierEnum::Metered);
        $gratis = $this->pieza('Fiestas de agosto', SnkKilkanaContentTierEnum::Free);

        SnkKilkanaReaderReadModel::record($amparo->id, $medida);
        SnkKilkanaReaderReadModel::record($amparo->id, $medida);
        SnkKilkanaReaderReadModel::record($amparo->id, $gratis);
        SnkKilkanaReaderReadModel::record($amparo->id, $medida, now()->subMonth());

        $this->assertSame(2, SnkKilkanaReaderReadModel::query()->where('user_id', $amparo->id)->count());
        $this->assertSame(1, SnkKilkanaReaderReadModel::query()->inMonth()->count(), 'Releer no gasta otra; el mes pasado no es este.');
        $this->assertSame(0, SnkKilkanaReaderReadModel::query()->where('post_id', $gratis->id)->count(), 'Una gratuita no consume.');
    }

    private function comoModerador(): CoreUserModel
    {
        $elena = CoreUserModel::create(['name' => 'Elena', 'email' => 'elena@eldiario.example', 'password' => 'secret1234']);
        $elena->assignRole('moderador');
        $this->actingAs($elena, 'sanctum');

        return $elena;
    }

    private function lectores(array $query = []): SnkKilkanaReadersController
    {
        request()->query->replace($query);

        return app(SnkKilkanaReadersController::class);
    }

    private function lector(string $email, string $nombre): CoreUserModel
    {
        $lector = CoreUserModel::create(['name' => $nombre, 'email' => $email, 'password' => 'secret1234']);
        $lector->assignRole('reader');

        return $lector;
    }

    private function comentario(CoreUserModel $autor, SnkKilkanaPostModel $pieza, string $texto, string $estado): SnkKilkanaCommentModel
    {
        return SnkKilkanaCommentModel::create([
            'post_id' => $pieza->id,
            'user_id' => $autor->id,
            'body' => $texto,
            'status' => $estado,
        ]);
    }

    private function pieza(string $titulo, SnkKilkanaContentTierEnum $tier = SnkKilkanaContentTierEnum::Free): SnkKilkanaPostModel
    {
        $seccion = SnkKilkanaCategoryModel::firstOrCreate(['slug' => 'local'], ['name' => 'Local', 'status' => 'live', 'position' => 0]);
        $firma = SnkKilkanaAuthorModel::firstOrCreate(['slug' => 'redaccion'], ['alias' => 'Redacción']);

        return SnkKilkanaPostModel::create([
            'title' => $titulo,
            'slug' => str($titulo)->slug()->value(),
            'url_date' => '2026-07-01',
            'category_id' => $seccion->id,
            'author_id' => $firma->id,
            'status' => 'published',
            'publish_at' => '2026-07-01 08:00:00',
            'content_tier' => $tier,
        ]);
    }

    /** Un lector nace solo desde la calle, y lo único que puede hacer con su sesión es comentar. */
    public function test_un_lector_se_registra_solo_y_su_comentario_nace_pendiente(): void
    {
        $auth = new SnkKilkanaAuthController;
        $alta = fn (array $extra = []) => $auth->register(Request::create('/', 'POST', $extra + [
            'name' => 'Vecina Nueva', 'email' => 'vecina@lectores.example', 'password' => 'correcthorse1', 'password_confirmation' => 'correcthorse1',
        ]));

        $respuesta = $alta();
        $this->assertSame(201, $respuesta->getStatusCode());
        $this->assertNotEmpty($respuesta->getData(true)['token']);
        $vecina = CoreUserModel::query()->where('email', 'vecina@lectores.example')->first();
        $this->assertTrue($vecina->hasRole('reader'));
        $this->assertSame(['create comments'], $vecina->getAllPermissions()->pluck('name')->all(), 'Comentar y nada más.');

        try {
            $alta();
            $this->fail('El mismo correo no se registra dos veces.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        $pieza = $this->pieza('Plan');
        $this->actingAs($vecina, 'sanctum');
        $comentario = app(SnkKilkanaCommentsController::class)->store(Request::create('/', 'POST', ['post_id' => $pieza->id, 'body' => 'Hola']));
        $this->assertSame(201, $comentario->getStatusCode());
        $this->assertSame('pending', SnkKilkanaCommentModel::query()->where('user_id', $vecina->id)->first()->status->value);

        $this->comoModerador();
        $this->assertContains('vecina@lectores.example', array_column($this->lectores()->index()->getData(true)['data'], 'email'), 'Y aparece en Lectores.');
    }

    /** Retirar a un lector: lo que identifica se va, la conversación se queda sin nombre, y el boletín lo olvida. */
    public function test_retirar_a_un_lector_borra_lo_que_identifica_y_deja_sus_comentarios_sin_nombre(): void
    {
        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
        $vecina = $this->lector('vecina@lectores.example', 'Vecina Curiosa');
        $pieza = $this->pieza('Plan');
        $this->comentario($vecina, $pieza, 'Hola', 'approved');
        $this->comentario($vecina, $pieza, 'Pendiente', 'pending');
        SnkKilkanaSubscriberModel::create(['email' => 'vecina@lectores.example', 'status' => 'confirmed', 'consent_source' => 'public_form']);

        $this->assertSame(200, $this->lectores()->anonymize($vecina->id)->getStatusCode());

        $retirada = $vecina->fresh();
        $this->assertSame('Reader', $retirada->name);
        $this->assertNull($retirada->full_name);
        $this->assertStringStartsWith('retired-', $retirada->email);
        $this->assertNotNull($retirada->suspended_at);
        $this->assertSame(2, SnkKilkanaCommentModel::query()->where('user_id', $vecina->id)->count(), 'Los comentarios se quedan.');
        $this->assertSame('rejected', SnkKilkanaCommentModel::query()->where('body', 'Pendiente')->first()->status->value);
        $this->assertSame(0, SnkKilkanaSubscriberModel::query()->where('email', 'vecina@lectores.example')->count());
        $this->assertSame(404, $this->lectores()->anonymize(999)->getStatusCode());
    }
}
