<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packages\Commander\Database\Seeders\CommanderDatabaseSeeder;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderDeploymentModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Core\Database\Seeders\CoreDatabaseSeeder;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Core\Models\CoreUserModel;
use Tests\CommanderTestCase;

class CommanderApiTest extends CommanderTestCase
{
    use RefreshDatabase;

    private function admin(): CoreUserModel
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(CommanderDatabaseSeeder::class);

        return CoreUserModel::where('email', 'admin@snake.com')->firstOrFail();
    }

    public function test_registry_requires_authentication(): void
    {
        $this->getJson('/api/v1/deployments')->assertStatus(401);
    }

    public function test_registry_starts_empty_then_lists_created_records(): void
    {
        $admin = $this->admin();

        // Demo-free seeder: the registry starts empty and only the admin user/permissions are seeded.
        $this->assertCount(0, $this->actingAs($admin, 'sanctum')->getJson('/api/v1/deployments')->json());
        $this->assertCount(0, $this->actingAs($admin, 'sanctum')->getJson('/api/v1/clients')->json());

        CommanderClientModel::create([
            'id' => 'acme', 'name' => 'Acme', 'company' => 'Acme', 'email' => 'ops@acme.test', 'status' => 'active',
        ]);

        $this->assertCount(1, $this->actingAs($admin, 'sanctum')->getJson('/api/v1/clients')->json());
    }

    public function test_deployment_shape_matches_spa_types(): void
    {
        $admin = $this->admin();

        CommanderClientModel::create([
            'id' => 'kestrel', 'name' => 'Kestrel Logistics', 'company' => 'Kestrel Logistics Co',
            'email' => 'it@kestrel.test', 'status' => 'active',
        ]);

        // State now lives on the commander_packages row, referenced by package_id.
        $package = CommanderPackageModel::create([
            'name' => 'TrackingApi', 'subdomain' => 'kestrel-track', 'status' => 'grace',
            'reason' => 'payment_overdue', 'message' => null,
            'grace_until' => now()->addHours(48), 'changed_by' => 'operator', 'changed_at' => now(),
        ]);

        CommanderDeploymentModel::create([
            'id' => 'kestrel-track', 'name' => 'kestrel-track', 'subdomain' => 'kestrel-track.snake.test',
            'client_id' => 'kestrel', 'package_id' => $package->id, 'package' => 'TrackingApi', 'version' => 'v2.9.3',
            'base_url' => 'https://kestrel-track.snake.test/api/v1',
        ]);
        foreach (['requests_day', 'disk_db_mb', 'disk_storage_mb', 'users_total'] as $metric) {
            CommanderQuotaModel::create([
                'id' => "kestrel-track:{$metric}", 'deployment_id' => 'kestrel-track', 'metric_key' => $metric,
                'current_value' => 1, 'limit_value' => 100, 'on_exceed' => 'none',
                'unit' => str_ends_with($metric, '_mb') ? 'mb' : 'count',
            ]);
        }

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/deployments/kestrel-track?with=client,quotas');
        $res->assertOk();
        $body = $res->json();

        // Nested state object (sourced from packages, not flat columns).
        $this->assertSame('grace', $body['state']['status']);
        $this->assertSame('payment_overdue', $body['state']['reason']);
        $this->assertNotNull($body['state']['grace_until']);

        // Relations resolve.
        $this->assertSame('Kestrel Logistics', $body['client']['name']);
        $this->assertCount(4, $body['quotas']);
    }

    public function test_state_actions_write_packages_in_process(): void
    {
        $admin = $this->admin();

        $package = CommanderPackageModel::create(['name' => 'Shop', 'subdomain' => 'shop', 'status' => 'active']);
        CommanderClientModel::create(['id' => 'c', 'name' => 'C', 'email' => 'c@c.test', 'status' => 'active']);
        CommanderDeploymentModel::create([
            'id' => 'shop', 'name' => 'shop', 'subdomain' => 'shop.snake.test', 'client_id' => 'c',
            'package_id' => $package->id, 'package' => 'Shop', 'version' => '1.0.0',
            'base_url' => 'https://shop.snake.test/api/v1',
        ]);

        // Suspend → writes the commander_packages row directly.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/deployments/shop/suspend', ['reason' => 'payment_overdue', 'message' => 'pay up'])
            ->assertOk()
            ->assertJsonPath('state.status', 'suspended')
            ->assertJsonPath('state.reason', 'payment_overdue');
        $this->assertSame('suspended', $package->fresh()->getRawOriginal('status'));

        // Resume → back to active.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/deployments/shop/resume')
            ->assertOk()
            ->assertJsonPath('state.status', 'active');
        $this->assertSame('active', $package->fresh()->getRawOriginal('status'));
    }

    public function test_deprovision_removes_commander_rows(): void
    {
        $admin = $this->admin();

        $package = CommanderPackageModel::create(['name' => 'Shop', 'subdomain' => 'shop', 'database' => 'shop', 'status' => 'active']);
        CommanderClientModel::create(['id' => 'c', 'name' => 'C', 'email' => 'c@c.test', 'status' => 'active']);
        CommanderDeploymentModel::create([
            'id' => 'shop', 'name' => 'shop', 'subdomain' => 'shop.snake.test', 'client_id' => 'c',
            'package_id' => $package->id, 'package' => 'Shop', 'version' => '1.0.0',
            'base_url' => 'https://shop.snake.test/api/v1',
        ]);
        CommanderQuotaModel::create([
            'id' => 'shop:requests_day', 'deployment_id' => 'shop', 'metric_key' => 'requests_day',
            'current_value' => 0, 'limit_value' => 100, 'on_exceed' => 'suspend', 'unit' => 'count',
        ]);

        // De-provision: en MariaDB hace DROP DATABASE; aqui (sqlite) limpia las filas de la BD commander.
        $this->actingAs($admin, 'sanctum')->deleteJson('/api/v1/deployments/shop')->assertOk();

        $this->assertNull(CommanderDeploymentModel::find('shop'));
        $this->assertNull(CommanderPackageModel::find($package->id));
        $this->assertCount(0, CommanderQuotaModel::where('deployment_id', 'shop')->get());
    }

    // Nota: el aislamiento operador↔tenant ya no necesita middleware: con BD por package, el token
    // de un tenant vive en SU BD y no existe en la commander -> 401 automatico. Se verifica en vivo
    // (no se puede modelar con una sola sqlite en tests).
}
