<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaAuthController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaRolesController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaUsersController;
use Packages\Kilkana\Mail\SnkKilkanaAccountLinkMail;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Un asiento es lo que se factura; un papel es lo que se puede hacer.
 *
 * Lo que se fija aqui es lo que separa a las cuentas de la redaccion de una tabla de usuarios:
 * quien ocupa asiento y quien no, los tres estados y lo que cada uno permite, que invitar
 * manda el enlace o no crea nada, y que nadie se cierra la puerta a si mismo.
 */
class KilkanaAccountsTest extends TestCase
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

        // Los cinco permisos de cuentas los da el seeder de Core, no el catalogo del periodico:
        // aqui se ponen a mano, como los pone ese seeder, para que admin sea admin.
        foreach (['index', 'show', 'create', 'update', 'delete'] as $accion) {
            Permission::findOrCreate("{$accion} users", 'sanctum');
        }
        Role::findByName('admin', 'sanctum')->givePermissionTo('index users', 'show users', 'create users', 'update users', 'delete users');
    }

    public function test_el_listado_es_de_la_redaccion_y_dice_estado_y_uso(): void
    {
        $this->comoAdmin();
        $ivan = $this->cuenta('ivan@eldiario.example', 'redactor');
        $this->pieza($ivan, 'Pleno de julio');
        $this->pieza($ivan, 'Carril bici');
        $this->cuenta('pau@eldiario.example', 'colaborador')->forceFill(['invited_at' => now()])->save();
        $this->cuenta('elena@eldiario.example', 'moderador')->forceFill(['suspended_at' => now()])->save();
        $this->cuenta('lector@eldiario.example', 'reader');

        $filas = collect($this->usuarios()->index()->getData(true))->keyBy('email');

        $this->assertArrayNotHasKey('lector@eldiario.example', $filas, 'Un lector no ocupa asiento y no sale.');
        $this->assertSame('redactor', $filas['ivan@eldiario.example']['role']);
        $this->assertSame('active', $filas['ivan@eldiario.example']['state']);
        $this->assertSame(2, $filas['ivan@eldiario.example']['posts_count']);
        $this->assertSame('invited', $filas['pau@eldiario.example']['state']);
        $this->assertSame('suspended', $filas['elena@eldiario.example']['state']);
        $this->assertArrayHasKey('last_active_at', $filas['ivan@eldiario.example']);
    }

    public function test_invitar_crea_la_cuenta_invitada_y_manda_el_enlace(): void
    {
        Mail::fake();
        $this->comoAdmin();

        $respuesta = $this->usuarios()->invite(Request::create('/', 'POST', [
            'email' => 'pau@eldiario.example', 'full_name' => 'Pau Server', 'role' => 'colaborador',
        ]));

        $this->assertSame(201, $respuesta->getStatusCode());
        $this->assertSame('invited', $respuesta->getData(true)['state']);
        $this->assertSame('colaborador', $respuesta->getData(true)['role']);
        Mail::assertSent(SnkKilkanaAccountLinkMail::class, fn (SnkKilkanaAccountLinkMail $correo) => $correo->kind === 'invite' && $correo->hasTo('pau@eldiario.example') && str_contains($correo->url, '/admin/password?'));
    }

    /** Un lector no es un asiento: por aqui no se invita. */
    public function test_no_se_invita_a_un_lector(): void
    {
        Mail::fake();
        $this->comoAdmin();

        $this->expectException(ValidationException::class);
        $this->usuarios()->invite(Request::create('/', 'POST', [
            'email' => 'lector@eldiario.example', 'full_name' => 'Un lector', 'role' => 'reader',
        ]));
    }

    /** Una invitada sin invitacion es un asiento pagado al que nadie puede llegar. */
    public function test_si_el_correo_no_sale_no_nace_la_cuenta(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));
        $this->comoAdmin();

        $respuesta = $this->usuarios()->invite(Request::create('/', 'POST', [
            'email' => 'pau@eldiario.example', 'full_name' => 'Pau Server', 'role' => 'colaborador',
        ]));

        $this->assertSame(502, $respuesta->getStatusCode());
        $this->assertNull(CoreUserModel::query()->where('email', 'pau@eldiario.example')->first());
    }

    public function test_suspender_tira_las_sesiones_y_el_login_no_entra_hasta_restaurar(): void
    {
        $this->comoAdmin();
        $elena = $this->cuenta('elena@eldiario.example', 'moderador');
        $elena->createToken('login');

        $respuesta = $this->usuarios()->suspend(Request::create('/', 'POST'), $elena->id);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame('suspended', $respuesta->getData(true)['state']);
        $this->assertSame(0, $elena->tokens()->count(), 'Las sesiones vivas se cortan.');

        $login = fn () => (new SnkKilkanaAuthController)->login(Request::create('/', 'POST', ['email' => 'elena@eldiario.example', 'password' => 'secret1234']));
        $this->assertSame(403, $login()->getStatusCode());
        $this->assertSame(0, $elena->tokens()->count(), 'Y el token del intento se revoca.');

        $this->usuarios()->restore(Request::create('/', 'DELETE'), $elena->id);
        $this->assertSame(200, $login()->getStatusCode());
    }

    public function test_nadie_se_cierra_la_puerta_a_si_mismo(): void
    {
        $admin = $this->comoAdmin();
        $usuarios = $this->usuarios();

        $this->assertSame(409, $usuarios->suspend(Request::create('/', 'POST'), $admin->id)->getStatusCode());
        $this->assertSame(409, $usuarios->destroy($admin->id)->getStatusCode());
        $this->assertSame(422, $usuarios->update(Request::create('/', 'PUT', ['role' => 'redactor']), $admin->id)->getStatusCode());
        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    public function test_el_reenvio_es_para_invitadas_y_el_enlace_de_contrasena_para_quien_entro(): void
    {
        Mail::fake();
        $this->comoAdmin();
        $ivan = $this->cuenta('ivan@eldiario.example', 'redactor');
        $pau = $this->cuenta('pau@eldiario.example', 'colaborador');
        $pau->forceFill(['invited_at' => now()])->save();

        $this->assertSame(409, $this->usuarios()->resendInvitation(Request::create('/', 'POST'), $ivan->id)->getStatusCode());
        $this->assertSame(409, $this->usuarios()->sendPasswordLink(Request::create('/', 'POST'), $pau->id)->getStatusCode());

        $this->assertSame(200, $this->usuarios()->resendInvitation(Request::create('/', 'POST'), $pau->id)->getStatusCode());
        $this->assertSame(200, $this->usuarios()->sendPasswordLink(Request::create('/', 'POST'), $ivan->id)->getStatusCode());
        Mail::assertSent(SnkKilkanaAccountLinkMail::class, fn ($correo) => $correo->kind === 'invite' && $correo->hasTo('pau@eldiario.example'));
        Mail::assertSent(SnkKilkanaAccountLinkMail::class, fn ($correo) => $correo->kind === 'reset' && $correo->hasTo('ivan@eldiario.example'));
    }

    public function test_canjear_el_enlace_pone_contrasena_y_cierra_la_invitacion(): void
    {
        $pau = $this->cuenta('pau@eldiario.example', 'colaborador');
        $pau->forceFill(['invited_at' => now()])->save();
        $token = Password::broker()->createToken($pau);
        $auth = new SnkKilkanaAuthController;

        $mal = $auth->resetPassword(Request::create('/', 'POST', ['email' => 'pau@eldiario.example', 'token' => 'otro', 'password' => 'correcthorse1', 'password_confirmation' => 'correcthorse1']));
        $this->assertSame(422, $mal->getStatusCode());

        $bien = $auth->resetPassword(Request::create('/', 'POST', ['email' => 'pau@eldiario.example', 'token' => $token, 'password' => 'correcthorse1', 'password_confirmation' => 'correcthorse1']));
        $this->assertSame(200, $bien->getStatusCode());
        $this->assertNull($pau->fresh()->invited_at);
        $this->assertTrue(Hash::check('correcthorse1', $pau->fresh()->password));
        $this->assertFalse(Password::broker()->tokenExists($pau, $token), 'El enlace se gasta al usarlo.');
    }

    public function test_los_papeles_viajan_con_sus_permisos_y_cuantos_los_tienen(): void
    {
        $this->comoAdmin();
        $this->cuenta('ivan@eldiario.example', 'redactor');
        $this->cuenta('lector@eldiario.example', 'reader');

        $roles = collect((new SnkKilkanaRolesController)->index()->getData(true))->keyBy('name');

        $this->assertCount(7, $roles);
        $this->assertSame(1, $roles['admin']['users_count']);
        $this->assertSame(1, $roles['redactor']['users_count']);
        $this->assertNotContains('publish posts', $roles['redactor']['permissions'], 'Un redactor manda a revision y no publica.');
        $this->assertContains('publish posts', $roles['editor']['permissions']);
        $this->assertFalse($roles['reader']['seat']);
        $this->assertTrue($roles['redactor']['seat']);
    }

    public function test_los_papeles_solo_los_ve_quien_reparte_asientos(): void
    {
        $this->actingAs($this->cuenta('ivan@eldiario.example', 'redactor'), 'sanctum');

        $this->assertSame(403, (new SnkKilkanaRolesController)->index()->getStatusCode());
    }

    private function comoAdmin(): CoreUserModel
    {
        $admin = $this->cuenta('marta@eldiario.example', 'admin');
        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    private function cuenta(string $email, string $rol): CoreUserModel
    {
        $usuario = CoreUserModel::create(['name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password' => 'secret1234']);
        $usuario->assignRole($rol);

        return $usuario;
    }

    private function usuarios(): SnkKilkanaUsersController
    {
        return app(SnkKilkanaUsersController::class);
    }

    private function pieza(CoreUserModel $autor, string $titulo): SnkKilkanaPostModel
    {
        $seccion = SnkKilkanaCategoryModel::firstOrCreate(['slug' => 'local'], ['name' => 'Local', 'status' => 'live', 'position' => 0]);
        $firma = SnkKilkanaAuthorModel::firstOrCreate(['user_id' => $autor->id]);

        return SnkKilkanaPostModel::create([
            'title' => $titulo,
            'slug' => str($titulo)->slug()->value(),
            'url_date' => '2026-07-01',
            'category_id' => $seccion->id,
            'author_id' => $firma->id,
            'status' => 'published',
            'publish_at' => '2026-07-01 08:00:00',
        ]);
    }
}
