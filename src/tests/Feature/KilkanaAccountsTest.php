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
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Un asiento es lo que se factura; un papel es lo que se puede hacer.
 *
 * Lo que se fija aquí es lo que separa a las cuentas de la redacción de una tabla de usuarios:
 * quién ocupa asiento y quién no, los tres estados y lo que cada uno permite, que invitar
 * manda el enlace o no crea nada, y que nadie se cierra la puerta a sí mismo.
 */
class KilkanaAccountsTest extends TestCase
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
        // El enlace del correo se construye con la URL del periódico, no con el host de la
        // petición: sin ajustes no hay enlace.
        SnkKilkanaSettingModel::create(['id' => 1, 'tenant_name' => 'El Diario', 'tenant_url' => 'https://demo.kilkana.test']);

        // Los cinco permisos de cuentas los da el seeder de Core, no el catálogo del periódico:
        // aquí se ponen a mano, como los pone ese seeder, para que admin sea admin.
        foreach (['index', 'show', 'create', 'update', 'delete'] as $action) {
            Permission::findOrCreate("{$action} users", 'sanctum');
        }
        Role::findByName('admin', 'sanctum')->givePermissionTo('index users', 'show users', 'create users', 'update users', 'delete users');
    }

    public function test_the_list_covers_the_newsroom_and_shows_status_and_usage(): void
    {
        $this->asAdmin();
        $ivan = $this->account('ivan@eldiario.example', 'redactor');
        $this->makePost($ivan, 'Pleno de julio');
        $this->makePost($ivan, 'Carril bici');
        $this->account('pau@eldiario.example', 'colaborador')->forceFill(['invited_at' => now()])->save();
        $this->account('elena@eldiario.example', 'moderador')->forceFill(['suspended_at' => now()])->save();
        $this->account('lector@eldiario.example', 'reader');

        $rows = collect($this->users()->index()->getData(true))->keyBy('email');

        $this->assertArrayNotHasKey('lector@eldiario.example', $rows, 'Un lector no ocupa asiento y no sale.');
        $this->assertSame('redactor', $rows['ivan@eldiario.example']['role']);
        $this->assertSame('active', $rows['ivan@eldiario.example']['state']);
        $this->assertSame(2, $rows['ivan@eldiario.example']['posts_count']);
        $this->assertSame('invited', $rows['pau@eldiario.example']['state']);
        $this->assertSame('suspended', $rows['elena@eldiario.example']['state']);
        $this->assertArrayHasKey('last_active_at', $rows['ivan@eldiario.example']);
    }

    public function test_inviting_creates_an_invited_account_and_sends_the_link(): void
    {
        Mail::fake();
        $this->asAdmin();

        $response = $this->users()->invite(Request::create('/', 'POST', [
            'email' => 'pau@eldiario.example', 'name' => 'Pau', 'lastname' => 'Server', 'role' => 'colaborador',
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('invited', $response->getData(true)['state']);
        $this->assertSame('colaborador', $response->getData(true)['role']);
        Mail::assertSent(SnkKilkanaAccountLinkMail::class, fn (SnkKilkanaAccountLinkMail $email) => $email->kind === 'invite' && $email->hasTo('pau@eldiario.example') && str_contains($email->url, '/admin/password?'));
    }

    /** Un lector no es un asiento: por aquí no se invita. */
    public function test_a_reader_cannot_be_invited(): void
    {
        Mail::fake();
        $this->asAdmin();

        $this->expectException(ValidationException::class);
        $this->users()->invite(Request::create('/', 'POST', [
            'email' => 'lector@eldiario.example', 'name' => 'Un', 'lastname' => 'lector', 'role' => 'reader',
        ]));
    }

    /** Una invitada sin invitación es un asiento pagado al que nadie puede llegar. */
    public function test_if_the_email_is_not_sent_no_account_is_created(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));
        $this->asAdmin();

        $response = $this->users()->invite(Request::create('/', 'POST', [
            'email' => 'pau@eldiario.example', 'name' => 'Pau', 'lastname' => 'Server', 'role' => 'colaborador',
        ]));

        $this->assertSame(502, $response->getStatusCode());
        $this->assertNull(CoreUserModel::query()->where('email', 'pau@eldiario.example')->first());
    }

    public function test_suspending_drops_sessions_and_blocks_login_until_restored(): void
    {
        $this->asAdmin();
        $elena = $this->account('elena@eldiario.example', 'moderador');
        $elena->createToken('login');

        $response = $this->users()->suspend(Request::create('/', 'POST'), $elena->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('suspended', $response->getData(true)['state']);
        $this->assertSame(0, $elena->tokens()->count(), 'Las sesiones vivas se cortan.');

        $login = fn () => (new SnkKilkanaAuthController)->login(Request::create('/', 'POST', ['email' => 'elena@eldiario.example', 'password' => 'secret1234']));
        $this->assertSame(403, $login()->getStatusCode());
        $this->assertSame(0, $elena->tokens()->count(), 'Y el token del intento se revoca.');

        $this->users()->restore(Request::create('/', 'DELETE'), $elena->id);
        $this->assertSame(200, $login()->getStatusCode());
    }

    public function test_nobody_can_lock_themselves_out(): void
    {
        $admin = $this->asAdmin();
        $users = $this->users();

        $this->assertSame(409, $users->suspend(Request::create('/', 'POST'), $admin->id)->getStatusCode());
        $this->assertSame(409, $users->destroy($admin->id)->getStatusCode());
        $this->assertSame(422, $users->update(Request::create('/', 'PUT', ['role' => 'redactor']), $admin->id)->getStatusCode());
        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    public function test_resend_is_for_invited_accounts_and_the_password_link_for_those_who_signed_in(): void
    {
        Mail::fake();
        $this->asAdmin();
        $ivan = $this->account('ivan@eldiario.example', 'redactor');
        $pau = $this->account('pau@eldiario.example', 'colaborador');
        $pau->forceFill(['invited_at' => now()])->save();

        $this->assertSame(409, $this->users()->resendInvitation(Request::create('/', 'POST'), $ivan->id)->getStatusCode());
        $this->assertSame(409, $this->users()->sendPasswordLink(Request::create('/', 'POST'), $pau->id)->getStatusCode());

        $this->assertSame(200, $this->users()->resendInvitation(Request::create('/', 'POST'), $pau->id)->getStatusCode());
        $this->assertSame(200, $this->users()->sendPasswordLink(Request::create('/', 'POST'), $ivan->id)->getStatusCode());
        Mail::assertSent(SnkKilkanaAccountLinkMail::class, fn ($email) => $email->kind === 'invite' && $email->hasTo('pau@eldiario.example'));
        Mail::assertSent(SnkKilkanaAccountLinkMail::class, fn ($email) => $email->kind === 'reset' && $email->hasTo('ivan@eldiario.example'));
    }

    public function test_redeeming_the_link_sets_the_password_and_closes_the_invitation(): void
    {
        $pau = $this->account('pau@eldiario.example', 'colaborador');
        $pau->forceFill(['invited_at' => now()])->save();
        $token = Password::broker()->createToken($pau);
        $auth = new SnkKilkanaAuthController;

        $badToken = $auth->resetPassword(Request::create('/', 'POST', ['email' => 'pau@eldiario.example', 'token' => 'otro', 'password' => 'correcthorse1', 'password_confirmation' => 'correcthorse1']));
        $this->assertSame(422, $badToken->getStatusCode());

        $reset = $auth->resetPassword(Request::create('/', 'POST', ['email' => 'pau@eldiario.example', 'token' => $token, 'password' => 'correcthorse1', 'password_confirmation' => 'correcthorse1']));
        $this->assertSame(200, $reset->getStatusCode());
        $this->assertNull($pau->fresh()->invited_at);
        $this->assertTrue(Hash::check('correcthorse1', $pau->fresh()->password));
        $this->assertFalse(Password::broker()->tokenExists($pau, $token), 'El enlace se gasta al usarlo.');
    }

    public function test_roles_include_their_permissions_and_how_many_accounts_hold_them(): void
    {
        $this->asAdmin();
        $this->account('ivan@eldiario.example', 'redactor');
        $this->account('lector@eldiario.example', 'reader');

        $roles = collect((new SnkKilkanaRolesController)->index()->getData(true))->keyBy('name');

        $this->assertCount(7, $roles);
        $this->assertSame(1, $roles['admin']['users_count']);
        $this->assertSame(1, $roles['redactor']['users_count']);
        $this->assertNotContains('publish posts', $roles['redactor']['permissions'], 'Un redactor manda a revisión y no publica.');
        $this->assertContains('publish posts', $roles['editor']['permissions']);
        $this->assertFalse($roles['reader']['seat']);
        $this->assertTrue($roles['redactor']['seat']);
    }

    public function test_only_whoever_assigns_seats_can_see_the_roles(): void
    {
        $this->actingAs($this->account('ivan@eldiario.example', 'redactor'), 'sanctum');

        $this->assertSame(403, (new SnkKilkanaRolesController)->index()->getStatusCode());
    }

    private function asAdmin(): CoreUserModel
    {
        $admin = $this->account('marta@eldiario.example', 'admin');
        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    private function account(string $email, string $role): CoreUserModel
    {
        $user = CoreUserModel::create(['name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password' => 'secret1234']);
        $user->assignRole($role);

        return $user;
    }

    private function users(): SnkKilkanaUsersController
    {
        return app(SnkKilkanaUsersController::class);
    }

    private function makePost(CoreUserModel $author, string $title): SnkKilkanaPostModel
    {
        $section = SnkKilkanaCategoryModel::firstOrCreate(['slug' => 'local'], ['name' => 'Local', 'status' => 'live', 'position' => 0]);
        $byline = SnkKilkanaAuthorModel::firstOrCreate(['user_id' => $author->id]);

        return SnkKilkanaPostModel::create([
            'title' => $title,
            'slug' => str($title)->slug()->value(),
            'url_date' => '2026-07-01',
            'category_id' => $section->id,
            'author_id' => $byline->id,
            'status' => 'published',
            'publish_at' => '2026-07-01 08:00:00',
        ]);
    }

    public function test_a_forgotten_password_requests_a_link_and_the_response_reveals_nobody(): void
    {
        Mail::fake();
        $ivan = $this->account('ivan@eldiario.example', 'redactor');
        $pau = $this->account('pau@eldiario.example', 'colaborador');
        $pau->forceFill(['invited_at' => now()])->save();
        $request = fn (string $email) => (new SnkKilkanaAuthController)->sendPasswordLink(Request::create('/', 'POST', ['email' => $email]));

        $this->assertSame(202, $request('ivan@eldiario.example')->getStatusCode());
        $this->assertSame(202, $request('pau@eldiario.example')->getStatusCode(), 'Invitada: igual por fuera.');
        $this->assertSame(202, $request('nadie@eldiario.example')->getStatusCode(), 'Inexistente: igual por fuera.');

        // A la cola y no al SMTP: la respuesta no puede tardar distinto según exista la cuenta.
        Mail::assertNothingSent();
        Mail::assertQueued(SnkKilkanaAccountLinkMail::class, 1);
        Mail::assertQueued(SnkKilkanaAccountLinkMail::class, fn ($email) => $email->kind === 'reset'
            && $email->hasTo('ivan@eldiario.example')
            && str_starts_with($email->url, 'https://demo.kilkana.test/admin/password?'));
        $this->assertTrue($ivan->fresh() !== null);
    }
}
