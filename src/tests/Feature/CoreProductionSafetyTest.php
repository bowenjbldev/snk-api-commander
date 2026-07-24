<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Packages\Commander\Jobs\CommanderDeprovisionPackageJob;
use Packages\Commander\Jobs\CommanderProvisionPackageJob;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Core\Database\Seeders\CoreDatabaseSeeder;
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
        $this->assertContains('snk.kilkana.users.index', $names);
    }

    public function test_queue_visibility_and_package_locks_cover_destructive_jobs(): void
    {
        $provision = new CommanderProvisionPackageJob(
            42,
            'acme',
            'Shop',
            null,
            'admin@acme.test',
            Crypt::encryptString('unique-password'),
        );
        $deprovision = new CommanderDeprovisionPackageJob(42);

        $this->assertGreaterThan($provision->timeout, config('queue.connections.database.retry_after'));
        $this->assertGreaterThan($deprovision->timeout, config('queue.connections.database.retry_after'));
        $this->assertSame(
            $provision->middleware()[0]->getLockKey($provision),
            $deprovision->middleware()[0]->getLockKey($deprovision),
        );
    }

    public function test_all_tenant_migration_fails_closed_for_incomplete_registry_entry(): void
    {
        $client = CommanderClientModel::create([
            'slug' => 'broken',
            'name' => 'Broken',
            'email' => 'ops@broken.test',
            'status' => 'active',
        ]);
        CommanderPackageModel::create([
            'name' => 'Shop',
            'slug' => 'shop',
            'client_id' => $client->id,
            'status' => 'active',
            'provisioned_at' => now(),
        ]);

        $this->artisan('core:migrate --all-tenants --force')->assertExitCode(1);
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
