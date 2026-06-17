<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Packages\Commander\Database\Seeders\CommanderDatabaseSeeder;
use Packages\Commander\Models\ClientModel;
use Packages\Commander\Models\DeploymentModel;
use Packages\Commander\Models\PlanModel;
use Packages\Core\Database\Seeders\CoreDatabaseSeeder;
use Packages\Core\Models\CoreUserModel;
use Tests\TestCase;

class CommanderProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): CoreUserModel
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(CommanderDatabaseSeeder::class);

        return CoreUserModel::where('email', 'admin@snake.com')->firstOrFail();
    }

    private function plan(): PlanModel
    {
        return PlanModel::create([
            'id' => 'demo-pro', 'package' => 'Demo', 'name' => 'Pro', 'price_monthly' => 49,
            'q_requests_day' => 100000, 'q_disk_db_mb' => 2048, 'q_users_total' => 50,
        ]);
    }

    public function test_provisioning_creates_pending_subscription_from_client_and_plan(): void
    {
        $admin = $this->admin();
        $this->plan();

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provisioning', [
            'plan_id' => 'demo-pro',
            'client' => [
                'id' => 'acme', 'name' => 'Acme Inc', 'email' => 'hi@acme.com',
                'billing_email' => 'billing@acme.com', 'tax_id' => 'ESB12345678',
                'address_line1' => 'Calle Mayor 1', 'city' => 'Madrid', 'country' => 'ES',
                'payment_terms' => 'net_30',
            ],
        ]);

        $res->assertStatus(201);
        $res->assertJsonPath('deployment.state.status', 'provisioning');
        $res->assertJsonPath('deployment.package', 'Demo');
        $res->assertJsonPath('deployment.subscription.plan', 'Pro');
        $res->assertJsonPath('host_command', 'bash scripts/subscription.sh add Demo --client=acme');

        // Client created with full billing.
        $client = ClientModel::find('acme');
        $this->assertSame('billing@acme.com', $client->billing_email);
        $this->assertSame('ESB12345678', $client->tax_id);
        $this->assertSame('ES', $client->country);

        // Pending deployment + subscription + quotas mirrored from the plan.
        $dep = DeploymentModel::find('demo');
        $this->assertSame('provisioning', $dep->getAttribute('status'));
        $this->assertNotNull($dep->subscription_id);
        $this->assertNull($dep->control_secret);
        $this->assertCount(4, $dep->quotas);
        $this->assertSame(100000, (int) $dep->quotas->firstWhere('metric_key', 'requests_day')->limit_value);
        $this->assertSame('suspend', $dep->quotas->firstWhere('metric_key', 'requests_day')->on_exceed);
        // disk_storage_mb had no plan limit → unlimited / none.
        $this->assertNull($dep->quotas->firstWhere('metric_key', 'disk_storage_mb')->limit_value);
    }

    public function test_provisioning_requires_permission(): void
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(CommanderDatabaseSeeder::class);
        $this->plan();

        $user = CoreUserModel::create([
            'email' => 'noperm@snake.com', 'name' => 'No Perm', 'full_name' => 'No Perm', 'password' => 'secret1234',
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/provisioning', [
            'plan_id' => 'demo-pro',
            'client' => ['id' => 'acme', 'name' => 'Acme', 'email' => 'a@a.com'],
        ])->assertStatus(403);
    }

    public function test_provisioning_conflicts_when_deployment_already_exists(): void
    {
        $admin = $this->admin();
        $this->plan();

        ClientModel::create(['id' => 'x', 'name' => 'X', 'email' => 'x@x.test', 'status' => 'active']);
        DeploymentModel::create([
            'id' => 'demo', 'name' => 'demo', 'subdomain' => 'demo.snake.test', 'client_id' => 'x',
            'package' => 'Demo', 'version' => 'v1.0.0', 'base_url' => 'https://demo.snake.test/api/v1',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provisioning', [
            'plan_id' => 'demo-pro',
            'client' => ['id' => 'acme', 'name' => 'Acme', 'email' => 'a@a.com'],
        ])->assertStatus(409);
    }

    public function test_register_deploy_activates_pending_without_clobbering_billing(): void
    {
        $admin = $this->admin();
        $this->plan();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provisioning', [
            'plan_id' => 'demo-pro',
            'client' => ['id' => 'acme', 'name' => 'Acme', 'email' => 'hi@acme.com', 'billing_email' => 'billing@acme.com'],
        ])->assertStatus(201);

        $subId = DeploymentModel::find('demo')->subscription_id;

        Artisan::call('commander:register-deploy', [
            'deployment' => 'demo',
            '--package' => 'Demo',
            '--base-url' => 'https://demo.snake.test/api/v1',
            '--secret' => 'sk_ctl_live_realsecret01',
            '--client' => 'acme',
        ]);

        $dep = DeploymentModel::find('demo');
        $this->assertSame('active', $dep->getAttribute('status'));
        $this->assertSame('https://demo.snake.test/api/v1', $dep->base_url);
        $this->assertNotNull($dep->control_secret);
        $this->assertSame($subId, $dep->subscription_id); // subscription link preserved
        $this->assertSame('Demo', $dep->package);          // planned package preserved
        $this->assertSame('v1.0.0', $dep->version);        // 'pending' → real version

        // Operator-set billing must survive activation (no ops@ clobber).
        $client = ClientModel::find('acme');
        $this->assertSame('billing@acme.com', $client->billing_email);
        $this->assertSame('hi@acme.com', $client->email);
    }

    public function test_register_deploy_direct_flow_creates_placeholder_client(): void
    {
        $this->admin();

        Artisan::call('commander:register-deploy', [
            'deployment' => 'standalone',
            '--package' => 'Demo',
            '--base-url' => 'https://standalone.snake.test/api/v1',
            '--secret' => 'sk_ctl_live_x',
        ]);

        $dep = DeploymentModel::find('standalone');
        $this->assertSame('active', $dep->getAttribute('status'));
        $this->assertNull($dep->subscription_id);
        $this->assertSame('ops@standalone.local', ClientModel::find('standalone')->email);
    }
}
