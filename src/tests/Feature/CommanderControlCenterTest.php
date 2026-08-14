<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Packages\Commander\Database\Seeders\CommanderDatabaseSeeder;
use Packages\Commander\Metrics\CommanderBuiltInMetricMeasurer;
use Packages\Commander\Models\CommanderAccountModel;
use Packages\Commander\Models\CommanderActivityEventModel;
use Packages\Commander\Models\CommanderApplicationMetricModel;
use Packages\Commander\Models\CommanderApplicationModel;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Commander\Models\CommanderMetricMeasurementModel;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Commander\Models\CommanderSubscriptionModel;
use Packages\Commander\Models\CommanderUsageRollupModel;
use Packages\Commander\Services\CommanderQuotaMeasurerService;
use Packages\Core\Database\Seeders\CoreDatabaseSeeder;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Metrics\SnkKilkanaMetricMeasurer;
use Tests\CommanderTestCase;

class CommanderControlCenterTest extends CommanderTestCase
{
    use RefreshDatabase;

    public function test_settings_and_quota_policy_are_persisted_by_the_control_plane(): void
    {
        $admin = $this->admin();
        config(['core.quotas.grace_hours' => 96]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/settings')
            ->assertOk()->assertJsonPath('default_grace_hours', 96);
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/settings', [
            'operator_name' => 'Jorge',
            'operator_email' => 'ops@snakepc.test',
            'default_grace_hours' => 120,
        ])->assertOk()->assertJsonPath('default_grace_hours', 120);

        [$instance, $metric] = $this->instanceWithMetric('requests_day');
        $quota = CommanderQuotaModel::create([
            'instance_id' => $instance->id,
            'metric_id' => $metric->id,
            'limit_value' => 1000,
            'action' => 'warn',
        ]);
        $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/quotas/{$quota->id}", [
            'limit_value' => 500,
            'warning_percent' => 80,
            'action' => 'suspend',
        ])->assertOk();

        $this->assertSame(500, $quota->fresh()->limit_value);
        $this->assertSame('suspend', $quota->fresh()->action);
    }

    public function test_plan_limits_only_accept_metrics_from_the_same_application(): void
    {
        $admin = $this->admin();
        $shop = CommanderApplicationModel::create([
            'package_name' => 'Shop', 'slug' => 'shop', 'name' => 'Shop',
            'status' => 'active', 'code_status' => 'available',
        ]);
        $blog = CommanderApplicationModel::create([
            'package_name' => 'Blog', 'slug' => 'blog', 'name' => 'Blog',
            'status' => 'active', 'code_status' => 'available',
        ]);
        $shopMetric = CommanderApplicationMetricModel::create([
            'application_id' => $shop->id, 'key' => 'orders', 'name' => 'Orders',
            'unit' => 'count', 'kind' => 'counter', 'window' => 'month', 'measurer' => 'x',
        ]);
        $blogMetric = CommanderApplicationMetricModel::create([
            'application_id' => $blog->id, 'key' => 'posts', 'name' => 'Posts',
            'unit' => 'count', 'kind' => 'counter', 'window' => 'month', 'measurer' => 'x',
        ]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/plans', [
            'application_id' => $shop->id,
            'slug' => 'pro',
            'name' => 'Pro',
            'price_monthly' => 30,
            'limits' => [[
                'metric_id' => $shopMetric->id,
                'limit_value' => 1000,
                'warning_percent' => 80,
                'action' => 'warn',
            ]],
        ])->assertCreated()
            ->assertJsonPath('application.id', $shop->id)
            ->assertJsonPath('limits.0.metric.key', 'orders');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/plans', [
            'application_id' => $shop->id,
            'slug' => 'invalid',
            'name' => 'Invalid',
            'price_monthly' => 10,
            'limits' => [['metric_id' => $blogMetric->id, 'limit_value' => 10]],
        ])->assertUnprocessable()->assertJsonValidationErrors('limits');
    }

    public function test_request_quota_enters_grace_and_records_the_instance_activity(): void
    {
        [$instance, $metric] = $this->instanceWithMetric('requests_day');
        CommanderQuotaModel::create([
            'instance_id' => $instance->id,
            'metric_id' => $metric->id,
            'limit_value' => 5,
            'warning_percent' => 80,
            'action' => 'suspend',
        ]);
        for ($i = 0; $i < 8; $i++) {
            CommanderRequestLogModel::create([
                'instance_id' => $instance->id,
                'method' => 'GET',
                'route' => '/api/v1/x',
                'path' => 'api/v1/x',
                'status' => 200,
                'duration_ms' => 1,
            ]);
        }

        app(CommanderQuotaMeasurerService::class)->measureAll();

        $this->assertSame('grace', $instance->fresh()->getRawOriginal('service_status'));
        $this->assertDatabaseHas('commander_activity_events', [
            'instance_id' => $instance->id,
            'kind' => 'quota_warning',
            'reason' => 'quota:requests_day',
        ]);
        $this->assertDatabaseHas('commander_activity_events', [
            'instance_id' => $instance->id,
            'kind' => 'grace',
            'reason' => 'quota_exceeded:requests_day',
        ]);
    }

    public function test_subscription_quota_aggregates_and_controls_all_active_instances(): void
    {
        $admin = $this->admin();
        [$first, $metric, $plan, $contract] = $this->instanceWithMetric('requests_day');
        $contract->update(['pricing_mode' => 'negotiated', 'monthly_amount' => 5000, 'max_instances' => 2]);
        $secondClient = CommanderClientModel::create([
            'account_id' => $first->client->account_id,
            'slug' => 'tenant-two',
            'name' => 'Tenant Two',
            'email' => 'tenant-two@test.test',
        ]);
        $second = CommanderInstanceModel::create([
            'client_id' => $secondClient->id,
            'application_id' => $first->application_id,
            'database' => 'tenant_two_shop',
            'database_username' => 'tenant_2',
            'database_password' => 'runtime-password',
            'provisioning_status' => 'ready',
            'service_status' => 'active',
            'provisioned_at' => now(),
        ]);
        $contract->items()->create([
            'instance_id' => $second->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
        CommanderQuotaModel::create([
            'instance_id' => $first->id,
            'metric_id' => $metric->id,
            'limit_value' => 1,
            'action' => 'warn',
        ]);
        CommanderQuotaModel::create(['instance_id' => $second->id, 'metric_id' => $metric->id]);
        foreach ([[$first, 7], [$second, 5]] as [$instance, $count]) {
            for ($i = 0; $i < $count; $i++) {
                CommanderRequestLogModel::create([
                    'instance_id' => $instance->id,
                    'method' => 'GET',
                    'route' => '/api/v1/x',
                    'path' => 'api/v1/x',
                    'status' => 200,
                    'duration_ms' => 1,
                ]);
            }
        }

        $otherApplication = CommanderApplicationModel::create([
            'package_name' => 'Other', 'slug' => 'other', 'name' => 'Other',
            'status' => 'active', 'code_status' => 'available',
        ]);
        $otherMetric = CommanderApplicationMetricModel::create([
            'application_id' => $otherApplication->id,
            'key' => 'other', 'name' => 'Other', 'unit' => 'count',
            'kind' => 'counter', 'window' => 'day', 'measurer' => CommanderBuiltInMetricMeasurer::class,
        ]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/quotas', [
            'subscription_id' => $contract->id,
            'metric_id' => $otherMetric->id,
            'limit_value' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('metric_id');

        $sharedId = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/quotas', [
            'subscription_id' => $contract->id,
            'metric_id' => $metric->id,
            'limit_value' => 10,
            'warning_percent' => 80,
            'action' => 'suspend',
        ])->assertCreated()
            ->assertJsonPath('instance_id', null)
            ->assertJsonPath('subscription.id', $contract->id)
            ->json('id');

        app(CommanderQuotaMeasurerService::class)->measureAll();

        $shared = CommanderQuotaModel::findOrFail($sharedId);
        $this->assertSame(12, $shared->current_value);
        $this->assertSame('grace', $first->fresh()->getRawOriginal('service_status'));
        $this->assertSame('grace', $second->fresh()->getRawOriginal('service_status'));
        $this->assertDatabaseHas('commander_metric_measurements', ['quota_id' => $shared->id, 'value' => 12]);
        $this->assertDatabaseHas('commander_activity_events', [
            'instance_id' => null,
            'subscription_id' => $contract->id,
            'kind' => 'quota_warning',
        ]);
        $this->assertSame(2, CommanderActivityEventModel::query()
            ->where('subscription_id', $contract->id)->where('kind', 'grace')->count());
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/subscriptions/{$contract->id}?with=quotas.metric")
            ->assertOk()
            ->assertJsonPath('quotas.0.current_value', 12);

        CommanderRequestLogModel::query()->where('instance_id', $second->id)->delete();
        app(CommanderQuotaMeasurerService::class)->measureAll();

        $this->assertSame(7, $shared->fresh()->current_value);
        $this->assertSame('active', $first->fresh()->getRawOriginal('service_status'));
        $this->assertSame('active', $second->fresh()->getRawOriginal('service_status'));
    }

    public function test_subscription_with_a_shared_quota_keeps_its_history(): void
    {
        $admin = $this->admin();
        [, $metric, , $contract] = $this->instanceWithMetric('requests_day');
        $contract->items()->delete();
        $contract->quotas()->create(['metric_id' => $metric->id, 'limit_value' => 100]);

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/subscriptions/{$contract->id}")
            ->assertConflict()
            ->assertJsonPath('error', 'Subscription has history; cancel it instead.');

        $this->assertNotNull($contract->fresh());
        $this->assertSame(1, $contract->quotas()->count());
    }

    public function test_kilkana_recommends_upgrade_only_after_two_sustained_monthly_evaluations(): void
    {
        [$instance] = $this->instanceWithMetric('requests_day');
        $metric = CommanderApplicationMetricModel::create([
            'application_id' => $instance->application_id,
            'key' => 'reads_month',
            'name' => 'Reads',
            'unit' => 'count',
            'kind' => 'counter',
            'window' => 'month',
            'measurer' => SnkKilkanaMetricMeasurer::class,
        ]);
        $quota = CommanderQuotaModel::create([
            'instance_id' => $instance->id,
            'metric_id' => $metric->id,
            'current_value' => 130,
            'limit_value' => 100,
            'warning_percent' => 80,
            'action' => 'recommend',
            'consecutive_periods' => 2,
        ])->load('metric');

        try {
            CarbonImmutable::setTestNow('2026-04-01 00:00:00');
            foreach (['2026-01-31', '2026-02-28', '2026-03-31'] as $day) {
                CommanderMetricMeasurementModel::create([
                    'quota_id' => $quota->id,
                    'bucket_date' => $day,
                    'value' => 120,
                    'measured_at' => $day.' 23:59:00',
                ]);
            }
            app(CommanderQuotaMeasurerService::class)->enforce($quota, CarbonImmutable::parse('2026-03-31'));
            $this->assertSame(1, $quota->fresh()->consecutive_exceeded);
            $this->assertNull($quota->fresh()->upgrade_recommended_at);

            CommanderMetricMeasurementModel::create([
                'quota_id' => $quota->id,
                'bucket_date' => '2026-04-30',
                'value' => 140,
                'measured_at' => '2026-04-30 23:59:00',
            ]);
            CarbonImmutable::setTestNow('2026-05-01 00:00:00');
            $quota = $quota->fresh()->load('metric');
            app(CommanderQuotaMeasurerService::class)->enforce($quota, CarbonImmutable::parse('2026-04-30'));

            $this->assertSame(2, $quota->fresh()->consecutive_exceeded);
            $this->assertNotNull($quota->fresh()->upgrade_recommended_at);
            $this->assertSame('active', $instance->fresh()->getRawOriginal('service_status'));
            $this->assertSame(1, CommanderActivityEventModel::where('kind', 'upgrade_recommended')->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_a_broken_metric_does_not_stop_the_other_instance_metrics(): void
    {
        [$instance, $requests] = $this->instanceWithMetric('requests_day');
        $broken = CommanderApplicationMetricModel::create([
            'application_id' => $instance->application_id,
            'key' => 'broken',
            'name' => 'Broken',
            'unit' => 'count',
            'kind' => 'gauge',
            'window' => 'current',
            'measurer' => \stdClass::class,
        ]);
        foreach ([$broken, $requests] as $metric) {
            CommanderQuotaModel::create([
                'instance_id' => $instance->id,
                'metric_id' => $metric->id,
            ]);
        }
        CommanderRequestLogModel::create([
            'instance_id' => $instance->id,
            'method' => 'GET',
            'route' => '/api/v1/x',
            'path' => 'api/v1/x',
            'status' => 200,
            'duration_ms' => 1,
        ]);

        $values = collect(app(CommanderQuotaMeasurerService::class)->measureAll(false, false))
            ->mapWithKeys(fn (array $result) => [$result['quota']->metric->key => $result['value']]);

        $this->assertNull($values['broken']);
        $this->assertSame(1, $values['requests_day']);
    }

    public function test_plan_change_replaces_operational_limits_without_changing_contract_price(): void
    {
        $admin = $this->admin();
        [$instance, $metric, $currentPlan, $contract] = $this->instanceWithMetric('requests_day');
        $currentPlan->limits()->create([
            'metric_id' => $metric->id,
            'limit_value' => 100,
            'action' => 'warn',
        ]);
        CommanderQuotaModel::create([
            'instance_id' => $instance->id,
            'metric_id' => $metric->id,
            'limit_value' => 100,
            'action' => 'warn',
        ]);
        $next = CommanderPlanModel::create([
            'application_id' => $instance->application_id,
            'slug' => 'pro',
            'name' => 'Pro',
            'price_monthly' => 99,
        ]);
        $next->limits()->create([
            'metric_id' => $metric->id,
            'limit_value' => 1000,
            'warning_percent' => 75,
            'action' => 'suspend',
        ]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/instances/{$instance->id}/change-plan", [
            'plan_id' => $next->id,
        ])->assertOk()->assertJsonPath('subscription_item.plan.id', $next->id);

        $this->assertSame(1000, $instance->quotas()->firstOrFail()->limit_value);
        $this->assertSame('suspend', $instance->quotas()->firstOrFail()->action);
        $this->assertSame(15.0, $contract->fresh()->monthly_amount);
    }

    public function test_logs_and_platform_usage_keep_instance_identity(): void
    {
        $admin = $this->admin();
        [$instance] = $this->instanceWithMetric('requests_day');
        CommanderRequestLogModel::create([
            'instance_id' => $instance->id,
            'method' => 'GET',
            'route' => '/api/v1/products',
            'path' => 'api/v1/products',
            'status' => 200,
            'duration_ms' => 7,
        ]);
        $bucket = CarbonImmutable::now('UTC')->startOfHour();
        foreach ([['2xx', 80, 0], ['5xx', 20, 20]] as [$class, $count, $errors]) {
            CommanderUsageRollupModel::create([
                'instance_id' => $instance->id,
                'period' => 'hour',
                'period_start' => $bucket,
                'route' => '/a',
                'method' => 'GET',
                'status_class' => $class,
                'request_count' => $count,
                'error_count' => $errors,
                'avg_duration_ms' => 10,
                'p95_duration_ms' => 20,
            ]);
        }

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/logs')
            ->assertOk()
            ->assertJsonPath('data.0.instance_id', $instance->id)
            ->assertJsonPath('data.0.application', 'shop')
            ->assertJsonPath('data.0.client', 'tenant');
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/usage/platform?period=hour&hours=48')
            ->assertOk()
            ->assertJsonPath('data.0.instance_id', $instance->id)
            ->assertJsonPath('data.0.request_count', 100)
            ->assertJsonPath('data.0.error_count', 20);
    }

    /** @return array{CommanderInstanceModel, CommanderApplicationMetricModel, CommanderPlanModel, CommanderSubscriptionModel} */
    private function instanceWithMetric(string $key): array
    {
        $account = CommanderAccountModel::create(['name' => 'Tenant', 'email' => 'tenant@test.test']);
        $client = CommanderClientModel::create([
            'account_id' => $account->id,
            'slug' => 'tenant',
            'name' => 'Tenant',
            'email' => 'tenant@test.test',
        ]);
        $application = CommanderApplicationModel::create([
            'package_name' => 'Shop',
            'slug' => 'shop',
            'name' => 'Shop',
            'status' => 'active',
            'code_status' => 'available',
        ]);
        $metric = CommanderApplicationMetricModel::create([
            'application_id' => $application->id,
            'key' => $key,
            'name' => $key,
            'unit' => 'count',
            'kind' => 'counter',
            'window' => 'day',
            'measurer' => CommanderBuiltInMetricMeasurer::class,
        ]);
        $plan = CommanderPlanModel::create([
            'application_id' => $application->id,
            'slug' => 'basic',
            'name' => 'Basic',
            'price_monthly' => 15,
        ]);
        $contract = CommanderSubscriptionModel::create([
            'account_id' => $account->id,
            'application_id' => $application->id,
            'name' => 'Tenant Shop',
            'pricing_mode' => 'catalog',
            'monthly_amount' => 15,
            'currency' => 'EUR',
            'status' => 'active',
            'max_instances' => 1,
        ]);
        $instance = CommanderInstanceModel::create([
            'client_id' => $client->id,
            'application_id' => $application->id,
            'database' => 'tenant_shop',
            'database_username' => 'tenant_1',
            'database_password' => 'runtime-password',
            'provisioning_status' => 'ready',
            'service_status' => 'active',
            'provisioned_at' => now(),
        ]);
        $contract->items()->create([
            'instance_id' => $instance->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        return [$instance, $metric, $plan, $contract];
    }

    private function admin(): CoreUserModel
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(CommanderDatabaseSeeder::class);

        return CoreUserModel::where('email', 'admin@snake.com')->firstOrFail();
    }
}
