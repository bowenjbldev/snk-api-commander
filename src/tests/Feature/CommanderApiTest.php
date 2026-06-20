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
            'slug' => 'acme', 'name' => 'Acme', 'company' => 'Acme', 'email' => 'ops@acme.test', 'status' => 'active',
        ]);

        $this->assertCount(1, $this->actingAs($admin, 'sanctum')->getJson('/api/v1/clients')->json());
    }

    public function test_deployment_shape_matches_spa_types(): void
    {
        $admin = $this->admin();

        $client = CommanderClientModel::create([
            'slug' => 'kestrel', 'name' => 'Kestrel Logistics', 'company' => 'Kestrel Logistics Co',
            'email' => 'it@kestrel.test', 'status' => 'active',
        ]);

        // State now lives on the commander_packages row, referenced by package_id.
        $package = CommanderPackageModel::create([
            'name' => 'TrackingApi', 'subdomain' => 'kestrel-track', 'status' => 'grace',
            'reason' => 'payment_overdue', 'message' => null,
            'grace_until' => now()->addHours(48), 'changed_by' => 'operator', 'changed_at' => now(),
        ]);

        $dep = CommanderDeploymentModel::create([
            'client_id' => $client->id, 'package_id' => $package->id,
        ]);
        foreach (['requests_day', 'disk_db_mb', 'disk_storage_mb', 'users_total'] as $metric) {
            CommanderQuotaModel::create([
                'deployment_id' => $dep->id, 'metric_key' => $metric,
                'current_value' => 1, 'limit_value' => 100, 'on_exceed' => 'none',
                'unit' => str_ends_with($metric, '_mb') ? 'mb' : 'count',
            ]);
        }

        $res = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/deployments/{$dep->id}?with=client,quotas");
        $res->assertOk();
        $body = $res->json();

        // Identidad + estado llegan como la relacion anidada `package` (sin columnas planas ni accessors).
        $this->assertSame('TrackingApi', $body['package']['name']);
        $this->assertSame('kestrel-track', $body['package']['subdomain']);
        $this->assertSame('grace', $body['package']['status']);
        $this->assertSame('payment_overdue', $body['package']['reason']);
        $this->assertNotNull($body['package']['grace_until']);
        $this->assertArrayNotHasKey('state', $body);
        $this->assertArrayNotHasKey('package_id', $body);

        // Relations resolve.
        $this->assertSame('Kestrel Logistics', $body['client']['name']);
        $this->assertCount(4, $body['quotas']);
    }

    public function test_state_actions_write_packages_in_process(): void
    {
        $admin = $this->admin();

        $package = CommanderPackageModel::create(['name' => 'Shop', 'subdomain' => 'shop', 'status' => 'active']);
        $client = CommanderClientModel::create(['slug' => 'c', 'name' => 'C', 'email' => 'c@c.test', 'status' => 'active']);
        $dep = CommanderDeploymentModel::create([
            'client_id' => $client->id, 'package_id' => $package->id,
        ]);

        // Suspend → writes the commander_packages row directly.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/deployments/{$dep->id}/suspend", ['reason' => 'payment_overdue', 'message' => 'pay up'])
            ->assertOk()
            ->assertJsonPath('package.status', 'suspended')
            ->assertJsonPath('package.reason', 'payment_overdue');
        $this->assertSame('suspended', $package->fresh()->getRawOriginal('status'));

        // Resume → back to active.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/deployments/{$dep->id}/resume")
            ->assertOk()
            ->assertJsonPath('package.status', 'active');
        $this->assertSame('active', $package->fresh()->getRawOriginal('status'));
    }

    public function test_deprovision_removes_commander_rows(): void
    {
        $admin = $this->admin();

        $package = CommanderPackageModel::create(['name' => 'Shop', 'subdomain' => 'shop', 'database' => 'shop', 'status' => 'active']);
        $client = CommanderClientModel::create(['slug' => 'c', 'name' => 'C', 'email' => 'c@c.test', 'status' => 'active']);
        $dep = CommanderDeploymentModel::create([
            'client_id' => $client->id, 'package_id' => $package->id,
        ]);
        CommanderQuotaModel::create([
            'deployment_id' => $dep->id, 'metric_key' => 'requests_day',
            'current_value' => 0, 'limit_value' => 100, 'on_exceed' => 'suspend', 'unit' => 'count',
        ]);

        // De-provision: en MariaDB hace DROP DATABASE; aqui (sqlite) limpia las filas de la BD commander.
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/deployments/{$dep->id}")->assertOk();

        $this->assertNull(CommanderDeploymentModel::find($dep->id));
        $this->assertNull(CommanderPackageModel::find($package->id));
        $this->assertCount(0, CommanderQuotaModel::where('deployment_id', $dep->id)->get());
    }

    // Nota: el aislamiento operador↔tenant ya no necesita middleware: con BD por package, el token
    // de un tenant vive en SU BD y no existe en la commander -> 401 automatico. Se verifica en vivo
    // (no se puede modelar con una sola sqlite en tests).
}
