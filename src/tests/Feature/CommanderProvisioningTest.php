<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packages\Commander\Database\Seeders\CommanderDatabaseSeeder;
use Packages\Commander\Models\ClientModel;
use Packages\Commander\Models\DeploymentModel;
use Packages\Commander\Models\PlanModel;
use Packages\Core\Database\Seeders\CoreDatabaseSeeder;
use Packages\Core\Models\CorePackageModel;
use Packages\Commander\Models\QuotaMetricModel;
use Packages\Core\Models\CoreUserModel;
use Tests\CommanderTestCase;

class CommanderProvisioningTest extends CommanderTestCase
{
    use RefreshDatabase;

    private function admin(): CoreUserModel
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(CommanderDatabaseSeeder::class);

        return CoreUserModel::where('email', 'admin@snake.com')->firstOrFail();
    }

    /** A plan for a REAL package that ships on disk (Shop), so provisioning can migrate + seed it. */
    private function plan(): PlanModel
    {
        return PlanModel::create([
            'id' => 'shop-pro', 'package' => 'Shop', 'name' => 'Pro', 'price_monthly' => 49,
            'q_requests_day' => 100000, 'q_disk_db_mb' => 2048, 'q_users_total' => 50,
        ]);
    }

    public function test_provisioning_creates_live_instance_from_client_and_plan(): void
    {
        $admin = $this->admin();
        $this->plan();

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provisioning', [
            'plan_id' => 'shop-pro',
            'client' => [
                'id' => 'acme', 'name' => 'Acme Inc', 'email' => 'hi@acme.com',
                'billing_email' => 'billing@acme.com', 'tax_id' => 'ESB12345678',
                'address_line1' => 'Calle Mayor 1', 'city' => 'Madrid', 'country' => 'ES',
                'payment_terms' => 'net_30',
            ],
        ]);

        $res->assertStatus(201);
        // One-step: live immediately, no host_command, state sourced from packages.
        $res->assertJsonPath('deployment.state.status', 'active');
        $res->assertJsonPath('deployment.package', 'Shop');
        $res->assertJsonPath('deployment.subscription.plan', 'Pro');
        $res->assertJsonMissingPath('host_command');

        // Instance row (packages) created + active + its own database name → subdomain is live.
        $cp = CorePackageModel::where('subdomain', 'shop')->first();
        $this->assertNotNull($cp);
        $this->assertSame('active', $cp->getRawOriginal('status'));
        $this->assertSame('shop', $cp->database); // BD propia del package

        // Client created with full billing.
        $client = ClientModel::find('acme');
        $this->assertSame('billing@acme.com', $client->billing_email);
        $this->assertSame('ESB12345678', $client->tax_id);
        $this->assertSame('ES', $client->country);

        // Deployment + subscription + quotas (SPA mirror) from the plan.
        $dep = DeploymentModel::find('shop');
        $this->assertNotNull($dep->subscription_id);
        $this->assertSame($cp->id, $dep->package_id);
        $this->assertCount(4, $dep->quotas);
        $this->assertSame(100000, (int) $dep->quotas->firstWhere('metric_key', 'requests_day')->limit_value);
        $this->assertSame('suspend', $dep->quotas->firstWhere('metric_key', 'requests_day')->on_exceed);
        $this->assertNull($dep->quotas->firstWhere('metric_key', 'disk_storage_mb')->limit_value);

        // Real enforcement rows (quota_metrics) seeded per package (commander).
        $this->assertSame(100000, (int) QuotaMetricModel::where('package_id', $cp->id)
            ->where('metric_key', 'requests_day')->value('limit_value'));

        // Nota: la BD del package (su base Laravel + negocio + admin) se crea con CREATE DATABASE +
        // core:migrate --connection=tenant, que sqlite no soporta; se omite en tests y se verifica
        // en vivo (shop/blog como BDs separadas).
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
            'plan_id' => 'shop-pro',
            'client' => ['id' => 'acme', 'name' => 'Acme', 'email' => 'a@a.com'],
        ])->assertStatus(403);
    }

    public function test_provisioning_rejects_package_without_code(): void
    {
        $admin = $this->admin();
        PlanModel::create([
            'id' => 'ghost-pro', 'package' => 'Ghost', 'name' => 'Pro', 'price_monthly' => 10,
        ]);

        // 'Ghost' package has no code on disk → 422.
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provisioning', [
            'plan_id' => 'ghost-pro',
            'client' => ['id' => 'acme', 'name' => 'Acme', 'email' => 'a@a.com'],
        ])->assertStatus(422);
    }

    public function test_provisioning_conflicts_when_instance_already_exists(): void
    {
        $admin = $this->admin();
        $this->plan();

        ClientModel::create(['id' => 'x', 'name' => 'X', 'email' => 'x@x.test', 'status' => 'active']);
        DeploymentModel::create([
            'id' => 'shop', 'name' => 'shop', 'subdomain' => 'shop.snake.test', 'client_id' => 'x',
            'package' => 'Shop', 'version' => 'v1.0.0', 'base_url' => 'https://shop.snake.test/api/v1',
        ]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provisioning', [
            'plan_id' => 'shop-pro',
            'client' => ['id' => 'acme', 'name' => 'Acme', 'email' => 'a@a.com'],
        ])->assertStatus(409);
    }
}
