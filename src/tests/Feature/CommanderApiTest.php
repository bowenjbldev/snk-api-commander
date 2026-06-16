<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packages\Commander\Database\Seeders\CommanderDatabaseSeeder;
use Packages\Commander\Models\ClientModel;
use Packages\Commander\Models\DeploymentModel;
use Packages\Commander\Models\QuotaModel;
use Packages\Core\Database\Seeders\CoreDatabaseSeeder;
use Packages\Core\Models\CoreUserModel;
use Tests\TestCase;

class CommanderApiTest extends TestCase
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

        ClientModel::create([
            'id' => 'acme', 'name' => 'Acme', 'company' => 'Acme', 'email' => 'ops@acme.test', 'status' => 'active',
        ]);

        $this->assertCount(1, $this->actingAs($admin, 'sanctum')->getJson('/api/v1/clients')->json());
    }

    public function test_deployment_shape_matches_spa_types(): void
    {
        $admin = $this->admin();

        ClientModel::create([
            'id' => 'kestrel', 'name' => 'Kestrel Logistics', 'company' => 'Kestrel Logistics Co',
            'email' => 'it@kestrel.test', 'status' => 'active',
        ]);
        DeploymentModel::create([
            'id' => 'kestrel-track', 'name' => 'kestrel-track', 'subdomain' => 'kestrel-track.test',
            'client_id' => 'kestrel', 'package' => 'tracking-api', 'version' => 'v2.9.3',
            'base_url' => 'https://kestrel-track.test/api/v1',
            'control_secret' => 'sk_ctl_live_aaaaaaaa3f2a',
            'status' => 'grace', 'reason' => 'payment_overdue',
            'grace_until' => now()->addHours(48), 'changed_by' => 'operator',
        ]);
        foreach (['requests_day', 'disk_db_mb', 'disk_storage_mb', 'users_total'] as $metric) {
            QuotaModel::create([
                'id' => "kestrel-track:{$metric}", 'deployment_id' => 'kestrel-track', 'metric_key' => $metric,
                'current_value' => 1, 'limit_value' => 100, 'on_exceed' => 'none',
                'unit' => str_ends_with($metric, '_mb') ? 'mb' : 'count',
            ]);
        }

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/deployments/kestrel-track?with=client,quotas');
        $res->assertOk();
        $body = $res->json();

        // Nested state object (not flat columns).
        $this->assertSame('grace', $body['state']['status']);
        $this->assertSame('payment_overdue', $body['state']['reason']);
        $this->assertNotNull($body['state']['grace_until']);

        // Secret never leaks; only the 4-char suffix is exposed.
        $this->assertSame('3f2a', $body['control_secret_suffix']);
        $this->assertArrayNotHasKey('control_secret', $body);

        // Relations resolve.
        $this->assertSame('Kestrel Logistics', $body['client']['name']);
        $this->assertCount(4, $body['quotas']);
    }
}
