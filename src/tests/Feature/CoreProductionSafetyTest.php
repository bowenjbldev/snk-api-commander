<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Packages\Commander\Jobs\CommanderDeprovisionInstanceJob;
use Packages\Commander\Jobs\CommanderProvisionInstanceJob;
use Packages\Commander\Models\CommanderAccountModel;
use Packages\Commander\Models\CommanderApplicationModel;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Core\Database\Seeders\CoreDatabaseSeeder;
use Packages\Core\Http\Middleware\CoreEnsureActiveMiddleware;
use Packages\Core\Services\CoreInstanceStateService;
use Packages\Core\Support\CoreActiveInstanceSupport;
use Packages\Core\Support\CorePackageSelectorSupport;
use Tests\TestCase;

class CoreProductionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_names_are_unique_across_packages(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter();

        $this->assertSame([], $names->duplicates()->unique()->values()->all());
        $this->assertContains('commander.users.index', $names);
        $this->assertContains('kilkana.users.index', $names);

        $routes = collect(Route::getRoutes()->getRoutes())->keyBy(fn ($route) => $route->getName());
        $this->assertSame('commander.snakepc.test', $routes['commander.users.index']->getDomain());
        $this->assertSame('{client}.commander.test', $routes['kilkana.users.index']->getDomain());
        $this->assertSame('api/v1/kilkana/users', $routes['kilkana.users.index']->uri());
        $this->assertSame('diario-norte', CorePackageSelectorSupport::subdomainFromHost('diario-norte.commander.test'));
        $this->assertNull(CorePackageSelectorSupport::subdomainFromHost('diario-norte.kilkana.test'));
    }

    public function test_shared_signed_routes_are_generated_for_the_requested_client_host(): void
    {
        app('url')->setRequest(Request::create('https://elmundo.commander.test/api/v1/kilkana'));

        $url = URL::signedRoute('kilkana.subscribe.confirm', ['client' => 'elmundo', 'subscriber' => 42]);

        $this->assertStringStartsWith('https://elmundo.commander.test/api/v1/kilkana/public/subscribe/confirm/42?', $url);
        $this->assertTrue(URL::hasValidSignature(Request::create($url)));
    }

    public function test_queue_visibility_and_instance_locks_cover_destructive_jobs(): void
    {
        $provision = new CommanderProvisionInstanceJob(
            42,
            'acme',
            'Shop',
            'admin@acme.test',
            Crypt::encryptString('unique-password'),
        );
        $deprovision = new CommanderDeprovisionInstanceJob(42);

        $this->assertGreaterThan($provision->timeout, config('queue.connections.database.retry_after'));
        $this->assertGreaterThan($deprovision->timeout, config('queue.connections.database.retry_after'));
        $this->assertSame(
            $provision->middleware()[0]->getLockKey($provision),
            $deprovision->middleware()[0]->getLockKey($deprovision),
        );
    }

    public function test_tenant_state_check_fails_closed(): void
    {
        $state = \Mockery::mock(CoreInstanceStateService::class);
        $state->shouldReceive('current')->once()->andThrow(new \RuntimeException('cache unavailable'));
        $active = new CoreActiveInstanceSupport;
        $active->set((new CommanderInstanceModel)->forceFill(['id' => 42]));

        $response = (new CoreEnsureActiveMiddleware($state, $active))->handle(
            Request::create('/api/v1/kilkana/posts'),
            fn () => response('should not run'),
        );

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('unavailable', $response->getData(true)['status']);
    }

    public function test_all_tenant_migration_fails_closed_for_incomplete_registry_entry(): void
    {
        $account = CommanderAccountModel::create(['name' => 'Broken', 'email' => 'ops@broken.test']);
        $client = CommanderClientModel::create([
            'account_id' => $account->id,
            'slug' => 'broken',
            'name' => 'Broken',
            'email' => 'ops@broken.test',
            'status' => 'active',
        ]);
        $application = CommanderApplicationModel::create([
            'package_name' => 'Shop',
            'slug' => 'shop',
            'name' => 'Shop',
            'status' => 'active',
            'code_status' => 'available',
        ]);
        CommanderInstanceModel::create([
            'client_id' => $client->id,
            'application_id' => $application->id,
            'service_status' => 'active',
            'provisioning_status' => 'ready',
            'provisioned_at' => now(),
        ]);

        $this->artisan('core:migrate --all-tenants --force')->assertExitCode(1);
    }

    /**
     * Rehacer todos los periódicos es de local: sin `--fresh` no siembra —un despliegue no
     * vuelve a sembrar— y en producción no hay combinación que lo abra.
     */
    public function test_rebuilding_every_tenant_is_local_only_and_seeding_needs_fresh(): void
    {
        $this->artisan('core:migrate --all-tenants --seed --force')->assertExitCode(1);
        $this->artisan('core:migrate --all-tenants --fresh --client=demo --force')->assertExitCode(1);
        // Sin instancias listas no hay nada que rehacer: pasa las puertas y termina bien.
        $this->artisan('core:migrate --all-tenants --fresh --seed --force')->assertExitCode(0);

        $env = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $this->artisan('core:migrate --all-tenants --fresh --force')->assertExitCode(1);
        } finally {
            $this->app['env'] = $env;
        }
    }

    public function test_tenant_seeding_never_copies_the_commander_admin(): void
    {
        DB::purge('tenant');
        $this->assertSame(0, Artisan::call('migrate', [
            '--path' => ['packages/Core/database/migrations'],
            '--database' => 'tenant',
            '--force' => true,
        ]));

        config([
            'core.auth.admin_email' => 'shared@example.test',
            'core.auth.admin_password' => 'shared-password',
        ]);
        $this->assertSame(0, Artisan::call('db:seed', [
            '--class' => CoreDatabaseSeeder::class,
            '--database' => 'tenant',
            '--force' => true,
        ]));

        $this->assertSame(0, DB::connection('tenant')->table('users')->count());
        $this->assertSame(1, DB::connection('tenant')->table('roles')->where('name', 'admin')->count());
    }
}
