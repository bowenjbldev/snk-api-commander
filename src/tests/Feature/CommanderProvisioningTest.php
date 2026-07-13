<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packages\Commander\Database\Seeders\CommanderDatabaseSeeder;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Core\Database\Seeders\CoreDatabaseSeeder;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Core\Models\CoreUserModel;
use Illuminate\Support\Facades\Queue;
use Packages\Commander\Jobs\CommanderProvisionPackageJob;
use Tests\CommanderTestCase;

class CommanderProvisioningTest extends CommanderTestCase
{
    use RefreshDatabase;

    /**
     * Fixture: el package es per-cliente y ANIDADO. provisioning exige is_dir(packages/<Client>/<Pkg>);
     * el cliente de los tests es 'acme' (Studly Acme), así que el código vive en packages/Acme/Demo.
     * En sqlite no se migra la BD tenant (solo se valida la parte commander).
     */
    private const FIXTURE_PACKAGE = 'Demo';
    private const FIXTURE_CLIENT_DIR = 'Acme';

    protected function setUp(): void
    {
        parent::setUp();

        @mkdir(base_path('packages/'.self::FIXTURE_CLIENT_DIR.'/'.self::FIXTURE_PACKAGE), 0777, true);
    }

    protected function tearDown(): void
    {
        @rmdir(base_path('packages/'.self::FIXTURE_CLIENT_DIR.'/'.self::FIXTURE_PACKAGE));
        @rmdir(base_path('packages/'.self::FIXTURE_CLIENT_DIR));

        parent::tearDown();
    }

    private function admin(): CoreUserModel
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(CommanderDatabaseSeeder::class);

        return CoreUserModel::where('email', 'admin@snake.com')->firstOrFail();
    }

    /** Plan de un tipo de package que existe en disco bajo algún cliente (fixture Acme/Demo). */
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
        Queue::fake();

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provisioning', [
            'plan_id' => $plan->id,
            'client' => [
                'slug' => 'acme', 'name' => 'Acme Inc', 'email' => 'hi@acme.com',
                'billing_email' => 'billing@acme.com', 'tax_id' => 'ESB12345678',
                'address_line1' => 'Calle Mayor 1', 'city' => 'Madrid', 'country' => 'ES',
                'payment_terms' => 'net_30',
            ],
        ]);

        $res->assertStatus(202);
        // La API registra el alta, pero el worker es quien la vuelve enrutable.
        $res->assertJsonPath('package.status', 'active');
        $res->assertJsonPath('package.name', 'Demo');
        $res->assertJsonPath('package.slug', 'demo');           // slug del package (path)
        $res->assertJsonPath('package.subscription.plan', 'Pro');

        // Instance row created + active + its own database (snake(client)_snake(package) = acme_demo).
        $cp = CommanderPackageModel::where('slug', 'demo')->first();
        $this->assertNotNull($cp);
        $this->assertSame('active', $cp->getRawOriginal('status'));
        $this->assertSame('acme_demo', $cp->database); // BD propia: {client}_{package}
        $this->assertNull($cp->provisioned_at);
        $res->assertJsonMissingPath('package.database_username');
        $res->assertJsonMissingPath('package.database_password');
        Queue::assertPushed(CommanderProvisionPackageJob::class, fn ($job) => $job->packageId === $cp->id);

        (new CommanderProvisionPackageJob($cp->id, 'acme', 'Demo', $cp->client_id))->handle();
        $this->assertNotNull($cp->fresh()->provisioned_at);

        // Client created with full billing; el subdominio ES su slug.
        $client = CommanderClientModel::where('slug', 'acme')->first();
        $this->assertSame('billing@acme.com', $client->billing_email);
        $this->assertSame('ESB12345678', $client->tax_id);
        $this->assertSame('ES', $client->country);
        $this->assertSame($client->id, $cp->client_id);

        // Subscription + quotas (tabla unica) hang off the package row (1:1).
        $this->assertNotNull($cp->subscription_id);
        $term = $cp->subscription;
        $this->assertSame($plan->id, $term->plan_id);
        $this->assertNull($term->ended_at);
        $this->assertCount(4, $cp->quotas);
        $this->assertSame(100000, (int) $cp->quotas->firstWhere('metric_key', 'requests_day')->limit_value);
        $this->assertSame('suspend', $cp->quotas->firstWhere('metric_key', 'requests_day')->on_exceed);
        $this->assertNull($cp->quotas->firstWhere('metric_key', 'disk_storage_mb')->limit_value);

        // Nota: la BD del package (base Laravel + negocio + admin) se crea con CREATE DATABASE +
        // core:migrate --client --package, que sqlite no soporta; se omite en tests y se verifica en vivo.
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
        // Plan de 'Ghost': ningún cliente tiene packages/<Client>/Ghost en disco.
        $plan = CommanderPlanModel::create([
            'slug' => 'ghost-pro', 'package' => 'Ghost', 'name' => 'Pro', 'price_monthly' => 10,
        ]);

        // El cliente acme no tiene packages/Acme/Ghost → 422.
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provisioning', [
            'plan_id' => $plan->id,
            'client' => ['slug' => 'acme', 'name' => 'Acme', 'email' => 'a@a.com'],
        ])->assertStatus(422);
    }

    public function test_provisioning_conflicts_when_instance_already_exists(): void
    {
        $admin = $this->admin();
        $plan = $this->plan();

        // Cliente con una instancia YA PROVISIONADA del mismo package (client_id, slug).
        $client = CommanderClientModel::create(['slug' => 'acme', 'name' => 'Acme', 'email' => 'a@a.com', 'status' => 'active']);
        CommanderPackageModel::create([
            'name' => 'Demo', 'label' => 'Demo', 'listing_status' => 'active',
            'slug' => 'demo', 'database' => 'acme_demo', 'client_id' => $client->id,
            'status' => 'active', 'provisioned_at' => now(),
        ]);

        // Re-provisionar el MISMO cliente + Demo => 409 (ya tiene 'demo').
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provisioning', [
            'plan_id' => $plan->id,
            'client_id' => $client->id,
        ])->assertStatus(409);
    }
}
