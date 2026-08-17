<?php

namespace Tests\Feature;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Packages\Commander\Database\Seeders\CommanderDatabaseSeeder;
use Packages\Commander\Jobs\CommanderDeprovisionInstanceJob;
use Packages\Commander\Models\CommanderAccountModel;
use Packages\Commander\Models\CommanderApplicationMetricModel;
use Packages\Commander\Models\CommanderApplicationModel;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Commander\Models\CommanderSubscriptionModel;
use Packages\Commander\Services\CommanderApplicationRegistryService;
use Packages\Core\Database\Seeders\CoreDatabaseSeeder;
use Packages\Core\Models\CoreUserModel;
use Tests\CommanderTestCase;

class CommanderApiTest extends CommanderTestCase
{
    use RefreshDatabase;

    public function test_control_plane_authentication_and_token_policy_are_server_owned(): void
    {
        $this->getJson('/api/v1/applications')->assertUnauthorized();
        $admin = $this->admin();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'secret1234',
            'name' => 'attacker',
            'abilities' => ['*'],
            'expires_at' => now()->addYears(10)->toIso8601String(),
        ])->assertOk();

        $token = $admin->tokens()->findOrFail($response->json('token_id'));
        $this->assertSame('login', $token->name);
        $this->assertSame(['api'], $token->abilities);
        $this->assertTrue($token->expires_at->isBefore(now()->addMinutes(1441)));
    }

    public function test_unknown_login_identifier_still_performs_password_verification(): void
    {
        $this->admin();
        $hasher = \Mockery::mock(Hasher::class);
        $hasher->shouldReceive('make')->once()->andReturn('dummy-hash');
        $hasher->shouldReceive('check')
            ->twice()
            ->withArgs(fn (string $password, string $hash): bool => $password === 'wrong-password' && $hash !== '')
            ->andReturnFalse();
        Hash::swap($hasher);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'missing@example.test',
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }
    }

    public function test_successful_logins_do_not_exhaust_the_shared_ip_failure_budget(): void
    {
        $admin = $this->admin();
        config([
            'core.rate_limits.login_account_failures_per_minute' => 2,
            'core.rate_limits.login_ip_failures_per_minute' => 2,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/v1/auth/login', [
                'email' => ['not-a-string'],
                'password' => 'wrong-password',
            ])->assertUnprocessable();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->postJson('/api/v1/auth/login', [
                    'email' => $admin->email,
                    'password' => 'secret1234',
                ])->assertOk();
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->postJson('/api/v1/auth/login', [
                    'email' => "missing-{$attempt}@example.test",
                    'password' => 'wrong-password',
                ])->assertUnauthorized();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'another-missing@example.test',
                'password' => 'wrong-password',
            ])->assertTooManyRequests();
    }

    public function test_login_failures_are_limited_per_normalized_account_across_ips(): void
    {
        $this->admin();
        config([
            'core.rate_limits.login_account_failures_per_minute' => 2,
            'core.rate_limits.login_ip_failures_per_minute' => 10,
        ]);

        foreach (['admin@snake.com', 'ADMIN@SNAKE.COM'] as $attempt => $email) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.2{$attempt}"])
                ->postJson('/api/v1/auth/login', [
                    'email' => $email,
                    'password' => 'wrong-password',
                ])->assertUnauthorized();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.22'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'Admin@Snake.Com',
                'password' => 'wrong-password',
            ])->assertTooManyRequests();
    }

    public function test_application_catalog_only_changes_through_explicit_sync(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/applications')->assertOk()->assertExactJson([]);
        app(CommanderApplicationRegistryService::class)->sync();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/applications?with=metrics')->assertOk();
        $this->assertContains('Kilkana', array_column($response->json(), 'package_name'));
        $response->assertJsonFragment(['key' => 'editorial_seats'])->assertJsonFragment(['key' => 'reads_month']);
    }

    public function test_instance_state_actions_are_scoped_by_numeric_instance_id(): void
    {
        $admin = $this->admin();
        [$first, $second] = [$this->makeInstance('one'), $this->makeInstance('two')];

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/instances/{$first->id}/suspend", [
            'reason' => 'payment_overdue',
            'message' => 'Contact billing',
        ])->assertOk()
            ->assertJsonPath('service_status', 'suspended')
            ->assertJsonPath('reason', 'payment_overdue');

        $this->assertSame('suspended', $first->fresh()->getRawOriginal('service_status'));
        $this->assertSame('active', $second->fresh()->getRawOriginal('service_status'));

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/instances/{$first->id}/resume")
            ->assertOk()->assertJsonPath('service_status', 'active');
    }

    public function test_deprovision_preserves_identity_and_commercial_history(): void
    {
        $admin = $this->admin();
        $instance = $this->makeInstance('soft');
        $metric = CommanderApplicationMetricModel::create([
            'application_id' => $instance->application_id,
            'key' => 'requests_day',
            'name' => 'Requests',
            'unit' => 'count',
            'kind' => 'counter',
            'window' => 'day',
            'measurer' => 'x',
        ]);
        $quota = CommanderQuotaModel::create([
            'instance_id' => $instance->id,
            'metric_id' => $metric->id,
            'current_value' => 120,
            'limit_value' => 100,
            'action' => 'warn',
            'consecutive_exceeded' => 2,
        ]);
        CommanderRequestLogModel::create([
            'instance_id' => $instance->id,
            'method' => 'GET',
            'route' => '/api/v1/x',
            'path' => 'api/v1/x',
            'status' => 200,
            'duration_ms' => 1,
        ]);
        Queue::fake();

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/instances/{$instance->id}")
            ->assertAccepted()->assertJsonPath('status', 'deprovisioning');
        Queue::assertPushed(CommanderDeprovisionInstanceJob::class, fn ($job) => $job->instanceId === $instance->id);

        $job = new CommanderDeprovisionInstanceJob($instance->id);
        $job->handle();

        $fresh = $instance->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('deprovisioned', $fresh->provisioning_status);
        $this->assertNotNull($fresh->deprovisioned_at);
        $this->assertSame(0, CommanderRequestLogModel::where('instance_id', $fresh->id)->count());
        $this->assertSame(0, $quota->fresh()->current_value);
        $this->assertSame(0, $quota->fresh()->consecutive_exceeded);
        $this->assertSame('ended', $fresh->subscriptionItem->status);
        $this->assertSame('canceled', $fresh->subscriptionItem->subscription->status);
        $this->assertSame(1, CommanderSubscriptionModel::count());
        $job->handle();
        $this->assertSame('deprovisioned', $instance->fresh()->provisioning_status);
    }

    public function test_invoice_lines_must_belong_to_the_invoice_account_and_total_is_computed(): void
    {
        $admin = $this->admin();
        $instance = $this->makeInstance('invoice');
        $subscription = $instance->subscriptionItem->subscription;

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/invoices', [
            'account_id' => $instance->client->account_id,
            'number' => 'INV-2026-001',
            'status' => 'pending',
            'lines' => [[
                'subscription_id' => $subscription->id,
                'subscription_item_id' => $instance->subscriptionItem->id,
                'description' => 'Monthly service',
                'quantity' => 3,
                'unit_amount' => 59.90,
            ]],
        ])->assertCreated()
            ->assertJsonPath('total_amount', 179.7)
            ->assertJsonPath('lines.0.amount', 179.7);

        $otherAccount = CommanderAccountModel::create(['name' => 'Other', 'email' => 'other@test.test']);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/invoices', [
            'account_id' => $otherAccount->id,
            'number' => 'INV-2026-002',
            'lines' => [[
                'subscription_id' => $subscription->id,
                'description' => 'Wrong account',
                'unit_amount' => 10,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.subscription_id');
    }

    private function makeInstance(string $slug): CommanderInstanceModel
    {
        $account = CommanderAccountModel::create(['name' => ucfirst($slug), 'email' => "{$slug}@test.test"]);
        $client = CommanderClientModel::create([
            'account_id' => $account->id,
            'slug' => $slug,
            'name' => ucfirst($slug),
            'email' => "{$slug}@test.test",
        ]);
        $application = CommanderApplicationModel::firstOrCreate(
            ['package_name' => 'Shop'],
            ['slug' => 'shop', 'name' => 'Shop', 'status' => 'active', 'code_status' => 'available'],
        );
        $plan = CommanderPlanModel::firstOrCreate(
            ['application_id' => $application->id, 'slug' => 'basic'],
            ['name' => 'Basic', 'price_monthly' => 15],
        );
        $subscription = CommanderSubscriptionModel::create([
            'account_id' => $account->id,
            'application_id' => $application->id,
            'name' => ucfirst($slug).' Shop',
            'pricing_mode' => 'catalog',
            'monthly_amount' => 15,
            'currency' => 'EUR',
            'status' => 'active',
            'max_instances' => 1,
        ]);
        $instance = CommanderInstanceModel::create([
            'client_id' => $client->id,
            'application_id' => $application->id,
            'database' => "{$slug}_shop",
            'database_username' => 'tenant_'.$client->id,
            'database_password' => 'runtime-password',
            'provisioning_status' => 'ready',
            'service_status' => 'active',
            'provisioned_at' => now(),
        ]);
        $subscription->items()->create([
            'instance_id' => $instance->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        return $instance->load(['client.account', 'application', 'subscriptionItem.subscription']);
    }

    private function admin(): CoreUserModel
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(CommanderDatabaseSeeder::class);

        return CoreUserModel::where('email', 'admin@snake.com')->firstOrFail();
    }
}
