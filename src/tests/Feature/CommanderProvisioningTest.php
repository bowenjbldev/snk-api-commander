<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packages\Commander\Database\Seeders\CommanderDatabaseSeeder;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Core\Database\Seeders\CoreDatabaseSeeder;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Core\Models\CoreUserModel;
use Tests\CommanderTestCase;

class CommanderProvisioningTest extends CommanderTestCase
{
    use RefreshDatabase;

    /** Paquete fixture: provisioning solo exige is_dir(packages/<Pkg>); en sqlite no migra la BD tenant. */
    private const FIXTURE_PACKAGE = 'Demo';

    protected function setUp(): void
    {
        parent::setUp();

        @mkdir(base_path('packages/'.self::FIXTURE_PACKAGE), 0777, true);
    }

    protected function tearDown(): void
    {
        @rmdir(base_path('packages/'.self::FIXTURE_PACKAGE));

        parent::tearDown();
    }

    private function admin(): CoreUserModel
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(CommanderDatabaseSeeder::class);

        return CoreUserModel::where('email', 'admin@snake.com')->firstOrFail();
    }

    /** Plan de un paquete que existe en disco (fixture Demo), para que provisioning lo de de alta. */
    private function plan(): CommanderPlanModel
    {
        return CommanderPlanModel::create([
            'slug' => 'demo-pro', 'package' => self::FIXTURE_PACKAGE, 'name' => 'Pro', 'price_monthly' => 49,
            'q_requests_day' => 100000, 'q_disk_db_mb' => 2048, 'q_users_total' => 50,
        ]);
    }

    public function test_provisioning_creates_live_instance_from_client_and_plan(): void
    {
        $admin = $this->admin();
        $plan = $this->plan();

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provisioning', [
            'plan_id' => $plan->id,
            'client' => [
                'slug' => 'acme', 'name' => 'Acme Inc', 'email' => 'hi@acme.com',
                'billing_email' => 'billing@acme.com', 'tax_id' => 'ESB12345678',
                'address_line1' => 'Calle Mayor 1', 'city' => 'Madrid', 'country' => 'ES',
                'payment_terms' => 'net_30',
            ],
        ]);

        $res->assertStatus(201);
        // One-step: live immediately. The live instance (commander_packages) is returned flat under `package`.
        $res->assertJsonPath('package.status', 'active');
        $res->assertJsonPath('package.name', 'Demo');
        $res->assertJsonPath('package.subdomain', 'demo');
        $res->assertJsonPath('package.subscription.plan', 'Pro');

        // Instance row (commander_packages) created + active + its own database name → subdomain is live.
        $cp = CommanderPackageModel::where('subdomain', 'demo')->first();
        $this->assertNotNull($cp);
        $this->assertSame('active', $cp->getRawOriginal('status'));
        $this->assertSame('demo', $cp->database); // BD propia del package
        $this->assertNotNull($cp->provisioned_at); // marcada como instancia viva

        // Client created with full billing.
        $client = CommanderClientModel::where('slug', 'acme')->first();
        $this->assertSame('billing@acme.com', $client->billing_email);
        $this->assertSame('ESB12345678', $client->tax_id);
        $this->assertSame('ES', $client->country);

        // Subscription + quotas (tabla unica) hang off the package row (1:1). El motor mide/aplica
        // estas mismas filas — no hay tabla de metrics aparte.
        $this->assertNotNull($cp->subscription_id);
        $this->assertSame($client->id, $cp->client_id);
        $this->assertCount(4, $cp->quotas);
        $this->assertSame(100000, (int) $cp->quotas->firstWhere('metric_key', 'requests_day')->limit_value);
        $this->assertSame('suspend', $cp->quotas->firstWhere('metric_key', 'requests_day')->on_exceed);
        $this->assertNull($cp->quotas->firstWhere('metric_key', 'disk_storage_mb')->limit_value);

        // Nota: la BD del package (su base Laravel + negocio + admin) se crea con CREATE DATABASE +
        // core:migrate --package, que sqlite no soporta; se omite en tests y se verifica
        // en vivo (demo/blog como BDs separadas).
    }

    public function test_provisioning_requires_permission(): void
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(CommanderDatabaseSeeder::class);
        $plan = $this->plan();

        $user = CoreUserModel::create([
            'email' => 'noperm@snake.com', 'name' => 'No Perm', 'full_name' => 'No Perm', 'password' => 'secret1234',
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/provisioning', [
            'plan_id' => $plan->id,
            'client' => ['slug' => 'acme', 'name' => 'Acme', 'email' => 'a@a.com'],
        ])->assertStatus(403);
    }

    public function test_provisioning_rejects_package_without_code(): void
    {
        $admin = $this->admin();
        $plan = CommanderPlanModel::create([
            'slug' => 'ghost-pro', 'package' => 'Ghost', 'name' => 'Pro', 'price_monthly' => 10,
        ]);

        // 'Ghost' package has no code on disk → 422.
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provisioning', [
            'plan_id' => $plan->id,
            'client' => ['slug' => 'acme', 'name' => 'Acme', 'email' => 'a@a.com'],
        ])->assertStatus(422);
    }

    public function test_provisioning_conflicts_when_instance_already_exists(): void
    {
        $admin = $this->admin();
        $plan = $this->plan();

        // El conflicto lo detecta una instancia YA PROVISIONADA (provisioned_at no nulo) del mismo package.
        CommanderPackageModel::create([
            'name' => 'Demo', 'label' => 'Demo', 'listing_status' => 'active',
            'subdomain' => 'demo', 'database' => 'demo', 'status' => 'active', 'provisioned_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provisioning', [
            'plan_id' => $plan->id,
            'client' => ['slug' => 'acme', 'name' => 'Acme', 'email' => 'a@a.com'],
        ])->assertStatus(409);
    }
}
