<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Packages\Commander\Database\Seeders\CommanderDatabaseSeeder;
use Packages\Commander\Jobs\CommanderProvisionInstanceJob;
use Packages\Commander\Models\CommanderAccountModel;
use Packages\Commander\Models\CommanderApplicationModel;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Commander\Models\CommanderSubscriptionModel;
use Packages\Commander\Services\CommanderApplicationRegistryService;
use Packages\Core\Database\Seeders\CoreDatabaseSeeder;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Metrics\SnkKilkanaMetricMeasurer;
use Tests\CommanderTestCase;

class CommanderAccountTest extends CommanderTestCase
{
    use RefreshDatabase;

    public function test_kilkana_group_uses_one_negotiated_contract_with_three_isolated_mastheads(): void
    {
        $admin = $this->admin();
        app(CommanderApplicationRegistryService::class)->sync();
        $application = CommanderApplicationModel::where('slug', 'kilkana')->firstOrFail();
        $seatMetric = $application->metrics()->where('key', 'editorial_seats')->firstOrFail();
        $readsMetric = $application->metrics()->where('key', 'reads_month')->firstOrFail();
        $plans = collect([
            ['basic', 'Basic', 29, 3, 100_000],
            ['pro', 'Pro', 59, 8, 500_000],
            ['business', 'Business', 99, 20, 2_000_000],
        ])->map(function (array $definition) use ($application, $seatMetric, $readsMetric) {
            [$slug, $name, $price, $seats, $reads] = $definition;
            $plan = CommanderPlanModel::create([
                'application_id' => $application->id,
                'slug' => $slug,
                'name' => $name,
                'price_monthly' => $price,
            ]);
            $plan->limits()->createMany([
                [
                    'metric_id' => $seatMetric->id,
                    'limit_value' => $seats,
                    'warning_percent' => 80,
                    'action' => 'recommend',
                    'consecutive_periods' => 2,
                ],
                [
                    'metric_id' => $readsMetric->id,
                    'limit_value' => $reads,
                    'warning_percent' => 80,
                    'action' => 'recommend',
                    'consecutive_periods' => 2,
                ],
            ]);

            return $plan;
        });
        $account = CommanderAccountModel::create([
            'name' => 'Grupo Kilkana',
            'email' => 'direccion@grupo.test',
            'billing_email' => 'facturacion@grupo.test',
            'tax_id' => 'ESA12345678',
        ]);
        $contract = CommanderSubscriptionModel::create([
            'account_id' => $account->id,
            'application_id' => $application->id,
            'name' => 'Kilkana Grupo 2026',
            'pricing_mode' => 'negotiated',
            'monthly_amount' => 180,
            'currency' => 'EUR',
            'status' => 'active',
            'max_instances' => 3,
            'started_at' => now(),
        ]);
        Queue::fake();

        foreach (['diario-norte', 'diario-centro', 'diario-sur'] as $index => $slug) {
            $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provisioning', [
                'plan_id' => $plans[$index]->id,
                'subscription_id' => $contract->id,
                'admin_email' => 'admin@grupo.test',
                'admin_password' => 'tenant-password-123',
                'client' => [
                    'account_id' => $account->id,
                    'slug' => $slug,
                    'name' => str($slug)->headline()->toString(),
                    'email' => "redaccion@{$slug}.test",
                ],
            ])->assertAccepted()
                ->assertJsonPath('instance.client.account.id', $account->id)
                ->assertJsonPath('instance.subscription_item.subscription.id', $contract->id)
                ->assertJsonPath('instance.database', str_replace('-', '_', $slug).'_kilkana');
        }

        $fourthPayload = [
            'plan_id' => $plans[0]->id,
            'subscription_id' => $contract->id,
            'admin_email' => 'admin@grupo.test',
            'admin_password' => 'tenant-password-123',
            'client' => [
                'account_id' => $account->id,
                'slug' => 'diario-cuarto',
                'name' => 'Diario Cuarto',
                'email' => 'redaccion@diario-cuarto.test',
            ],
        ];
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provisioning', $fourthPayload)
            ->assertUnprocessable();

        $instances = CommanderInstanceModel::query()->with('subscriptionItem')->orderBy('id')->get();
        $this->assertCount(3, $account->clients);
        $this->assertCount(3, $instances);
        $this->assertCount(3, $instances->pluck('database')->unique());
        $this->assertSame($plans->pluck('id')->all(), $instances->pluck('subscriptionItem.plan_id')->all());
        $this->assertTrue($instances->every(fn ($instance) => $instance->quotas()->count() === 7));
        $this->assertTrue($instances->every(fn ($instance) => $instance->quotas()->whereNull('limit_value')->count() === 5));
        $this->assertSame(1, CommanderSubscriptionModel::count());
        $this->assertSame(3, $contract->items()->count());
        $this->assertSame(180.0, $contract->monthly_amount);
        Queue::assertPushed(CommanderProvisionInstanceJob::class, 3);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/accounts/{$account->id}?include=clients.instances.application,subscriptions.items.instance")
            ->assertOk()
            ->assertJsonCount(3, 'clients')
            ->assertJsonCount(3, 'subscriptions.0.items');

        $first = $instances->first();
        $first->subscriptionItem->update(['status' => 'ended', 'ended_at' => now()]);
        $first->update(['provisioning_status' => 'deprovisioned', 'deprovisioned_at' => now()]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provisioning', $fourthPayload)->assertAccepted();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/instances/{$first->id}/retry", [
            'admin_email' => 'admin@grupo.test',
            'admin_password' => 'tenant-password-123',
        ])->assertUnprocessable();
    }

    public function test_kilkana_metrics_count_editorial_users_per_masthead_and_current_month_reads(): void
    {
        DB::purge('tenant');
        Schema::connection('tenant')->create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::connection('tenant')->create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('model_id');
            $table->string('model_type');
        });
        Schema::connection('tenant')->create('kilkana_post_views', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->unsignedInteger('engaged');
        });
        DB::connection('tenant')->table('roles')->insert([
            ['id' => 1, 'name' => 'reader'],
            ['id' => 2, 'name' => 'redactor'],
            ['id' => 3, 'name' => 'service'],
        ]);
        DB::connection('tenant')->table('model_has_roles')->insert([
            ['role_id' => 2, 'model_id' => 10, 'model_type' => CoreUserModel::class],
            ['role_id' => 1, 'model_id' => 11, 'model_type' => CoreUserModel::class],
            ['role_id' => 3, 'model_id' => 12, 'model_type' => CoreUserModel::class],
            ['role_id' => 1, 'model_id' => 13, 'model_type' => CoreUserModel::class],
            ['role_id' => 2, 'model_id' => 13, 'model_type' => CoreUserModel::class],
        ]);
        DB::connection('tenant')->table('kilkana_post_views')->insert([
            ['date' => now()->startOfMonth()->toDateString(), 'engaged' => 200],
            ['date' => now()->toDateString(), 'engaged' => 300],
            ['date' => now()->subMonth()->toDateString(), 'engaged' => 999],
        ]);

        app(CommanderApplicationRegistryService::class)->sync();
        $application = CommanderApplicationModel::where('slug', 'kilkana')->firstOrFail();
        $measurer = new SnkKilkanaMetricMeasurer;
        $instance = new CommanderInstanceModel;

        $this->assertSame(2, $measurer->measure(
            $instance,
            $application->metrics()->where('key', 'editorial_seats')->firstOrFail(),
            true,
        ));
        $this->assertSame(500, $measurer->measure(
            $instance,
            $application->metrics()->where('key', 'reads_month')->firstOrFail(),
            true,
        ));
    }

    private function admin(): CoreUserModel
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(CommanderDatabaseSeeder::class);

        return CoreUserModel::where('email', 'admin@snake.com')->firstOrFail();
    }
}
