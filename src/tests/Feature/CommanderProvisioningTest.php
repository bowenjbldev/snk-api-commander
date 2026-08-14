<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Packages\Commander\Database\Seeders\CommanderDatabaseSeeder;
use Packages\Commander\Jobs\CommanderProvisionInstanceJob;
use Packages\Commander\Models\CommanderAccountModel;
use Packages\Commander\Models\CommanderApplicationModel;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Commander\Models\CommanderSubscriptionModel;
use Packages\Commander\Services\CommanderApplicationRegistryService;
use Packages\Core\Database\Seeders\CoreDatabaseSeeder;
use Packages\Core\Models\CoreUserModel;
use Tests\CommanderTestCase;

class CommanderProvisioningTest extends CommanderTestCase
{
    use RefreshDatabase;

    private const PACKAGE = 'SimpleApp';

    protected function setUp(): void
    {
        parent::setUp();

        $files = new Filesystem;
        $files->ensureDirectoryExists(base_path('packages/'.self::PACKAGE.'/routes'));
        $files->put(base_path('packages/'.self::PACKAGE.'/routes/api.php'), '<?php');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(base_path('packages/'.self::PACKAGE));

        parent::tearDown();
    }

    public function test_simple_application_provisions_one_account_client_contract_and_instance(): void
    {
        $admin = $this->admin();
        app(CommanderApplicationRegistryService::class)->sync();
        $application = CommanderApplicationModel::where('package_name', self::PACKAGE)->firstOrFail();
        $metric = $application->metrics()->where('key', 'requests_day')->firstOrFail();
        $plan = CommanderPlanModel::create([
            'application_id' => $application->id,
            'slug' => 'pro',
            'name' => 'Pro',
            'price_monthly' => 30,
            'status' => 'active',
        ]);
        $plan->limits()->create([
            'metric_id' => $metric->id,
            'limit_value' => 10_000,
            'warning_percent' => 80,
            'action' => 'warn',
            'consecutive_periods' => 1,
        ]);
        Queue::fake();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provisioning', [
            'plan_id' => $plan->id,
            'admin_email' => 'owner@acme.test',
            'admin_password' => 'tenant-password-123',
            'client' => [
                'slug' => 'acme',
                'name' => 'Acme',
                'email' => 'contact@acme.test',
                'billing_email' => 'billing@acme.test',
                'tax_id' => 'ESA12345678',
                'country' => 'ES',
            ],
        ])->assertAccepted()
            ->assertJsonPath('instance.client.account.name', 'Acme')
            ->assertJsonPath('instance.application.slug', 'simple-app')
            ->assertJsonPath('instance.database', 'acme_simple_app')
            ->assertJsonPath('instance.provisioning_status', 'pending')
            ->assertJsonMissingPath('instance.database_username')
            ->assertJsonMissingPath('instance.database_password');

        $instance = CommanderInstanceModel::firstOrFail();
        $this->assertSame(1, CommanderAccountModel::count());
        $this->assertSame(1, CommanderClientModel::count());
        $this->assertSame(1, CommanderSubscriptionModel::count());
        $this->assertSame($plan->id, $instance->subscriptionItem->plan_id);
        $this->assertSame(10_000, $instance->quotas()->where('metric_id', $metric->id)->firstOrFail()->limit_value);
        $this->assertSame(5, $instance->quotas()->count());
        $this->assertSame('billing@acme.test', $instance->client->account->billing_email);
        $this->assertArrayNotHasKey('billing_email', $instance->client->getAttributes());
        Queue::assertPushed(CommanderProvisionInstanceJob::class, fn ($job) => $job->instanceId === $instance->id
            && $job->adminEmail === 'owner@acme.test');

        $job = new CommanderProvisionInstanceJob(
            $instance->id,
            'acme',
            self::PACKAGE,
            'owner@acme.test',
            Crypt::encryptString('tenant-password-123'),
        );
        $job->handle();

        $this->assertSame('ready', $instance->fresh()->provisioning_status);
        $this->assertNotNull($instance->fresh()->provisioned_at);
        $instance->forceFill(['provisioned_at' => '2020-01-01 00:00:00'])->save();
        $job->handle();
        $this->assertSame('2020-01-01 00:00:00', $instance->fresh()->provisioned_at->format('Y-m-d H:i:s'));
        $response->assertJsonStructure(['instance' => ['subscription_item' => ['subscription', 'plan']]]);
    }

    public function test_failed_instance_is_retried_with_the_same_identity(): void
    {
        $admin = $this->admin();
        app(CommanderApplicationRegistryService::class)->sync();
        $application = CommanderApplicationModel::where('package_name', self::PACKAGE)->firstOrFail();
        $account = CommanderAccountModel::create(['name' => 'Acme', 'email' => 'ops@acme.test']);
        $client = CommanderClientModel::create([
            'account_id' => $account->id,
            'slug' => 'acme',
            'name' => 'Acme',
            'email' => 'ops@acme.test',
        ]);
        $plan = CommanderPlanModel::create([
            'application_id' => $application->id,
            'slug' => 'basic',
            'name' => 'Basic',
            'price_monthly' => 15,
        ]);
        $subscription = CommanderSubscriptionModel::create([
            'account_id' => $account->id,
            'application_id' => $application->id,
            'name' => 'Acme SimpleApp',
            'pricing_mode' => 'catalog',
            'monthly_amount' => 15,
            'currency' => 'EUR',
            'status' => 'active',
            'max_instances' => 1,
        ]);
        $instance = CommanderInstanceModel::create([
            'client_id' => $client->id,
            'application_id' => $application->id,
            'database' => 'acme_simple_app',
            'provisioning_status' => 'failed',
            'service_status' => 'active',
            'last_error' => 'connection failed',
        ]);
        $subscription->items()->create([
            'instance_id' => $instance->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
        Queue::fake();

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/instances/{$instance->id}/retry", [
            'admin_email' => 'owner@acme.test',
            'admin_password' => 'new-password-123',
        ])->assertAccepted()->assertJsonPath('id', $instance->id);

        $this->assertSame(1, CommanderInstanceModel::count());
        $this->assertSame('pending', $instance->fresh()->provisioning_status);
        $this->assertNull($instance->fresh()->last_error);
        Queue::assertPushed(CommanderProvisionInstanceJob::class, fn ($job) => $job->instanceId === $instance->id);
    }

    private function admin(): CoreUserModel
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(CommanderDatabaseSeeder::class);

        return CoreUserModel::where('email', 'admin@snake.com')->firstOrFail();
    }
}
