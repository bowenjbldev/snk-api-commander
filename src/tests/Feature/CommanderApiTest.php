<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packages\Commander\Database\Seeders\CommanderDatabaseSeeder;
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

    public function test_lists_seeded_registry(): void
    {
        $admin = $this->admin();

        $deployments = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/deployments');
        $deployments->assertOk();
        $this->assertCount(15, $deployments->json());

        $this->assertCount(8, $this->actingAs($admin, 'sanctum')->getJson('/api/v1/clients')->json());
        $this->assertCount(8, $this->actingAs($admin, 'sanctum')->getJson('/api/v1/invoices')->json());
    }

    public function test_deployment_shape_matches_spa_types(): void
    {
        $admin = $this->admin();

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
