<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Packages\Commander\Database\Seeders\CommanderDatabaseSeeder;
use Packages\Commander\Jobs\CommanderProvisionPackageJob;
use Packages\Commander\Models\CommanderAccountModel;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Commander\Models\CommanderSubscriptionModel;
use Packages\Core\Database\Seeders\CoreDatabaseSeeder;
use Packages\Core\Models\CoreUserModel;
use Tests\CommanderTestCase;

class CommanderAccountTest extends CommanderTestCase
{
    use RefreshDatabase;

    public function test_email_is_contact_not_account_identity(): void
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(CommanderDatabaseSeeder::class);
        $admin = CoreUserModel::where('email', 'admin@snake.com')->firstOrFail();

        foreach (['Cuenta Norte', 'Cuenta Sur'] as $name) {
            $this->actingAs($admin, 'sanctum')->postJson('/api/v1/accounts', [
                'name' => $name,
                'email' => 'gestoria@shared.test',
            ])->assertCreated();
        }

        $this->assertSame(2, CommanderAccountModel::where('email', 'gestoria@shared.test')->count());
    }

    public function test_one_account_groups_three_isolated_kilkana_instances(): void
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(CommanderDatabaseSeeder::class);
        $admin = CoreUserModel::where('email', 'admin@snake.com')->firstOrFail();
        Queue::fake();

        $accountId = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/accounts', [
            'name' => 'Grupo Prensa Demo',
            'email' => 'direccion@grupo-prensa.test',
            'billing_email' => 'facturacion@grupo-prensa.test',
            'tax_id' => 'ESA12345678',
            'country' => 'ES',
            'payment_terms' => 'net_30',
        ])->assertCreated()->json('id');

        $plan = CommanderPlanModel::create([
            'slug' => 'kilkana-business-demo',
            'package' => 'Kilkana',
            'name' => 'Business',
            'price_monthly' => 99,
            'q_requests_day' => 100000,
            'q_disk_db_mb' => 2048,
            'q_disk_storage_mb' => 20480,
            'q_users_total' => 15,
        ]);

        $mastheads = [
            'diario-norte' => 'Diario Norte',
            'diario-centro' => 'Diario Centro',
            'diario-sur' => 'Diario Sur',
        ];

        foreach ($mastheads as $slug => $name) {
            $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provisioning', [
                'plan_id' => $plan->id,
                'admin_email' => "admin@{$slug}.test",
                'admin_password' => 'tenant-password-123',
                'client' => [
                    'account_id' => $accountId,
                    'slug' => $slug,
                    'name' => $name,
                    'email' => "redaccion@{$slug}.test",
                ],
            ])->assertAccepted()
                ->assertJsonPath('package.client.account.id', $accountId)
                ->assertJsonPath('package.database', str_replace('-', '_', $slug).'_kilkana');
        }

        $account = CommanderAccountModel::findOrFail($accountId);
        $clientIds = $account->clients()->pluck('id');
        $packages = CommanderPackageModel::query()->whereIn('client_id', $clientIds)->get();

        $this->assertSame(array_keys($mastheads), $account->clients()->orderBy('id')->pluck('slug')->all());
        $this->assertCount(3, $packages);
        $this->assertCount(3, $packages->pluck('database')->unique());
        $this->assertSame(3, CommanderSubscriptionModel::query()->whereIn('package_id', $packages->pluck('id'))->count());
        $this->assertTrue($packages->every(fn (CommanderPackageModel $package) => $package->quotas()->count() === 4));

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/accounts/{$accountId}?with=clients.packages")
            ->assertOk()
            ->assertJsonCount(3, 'clients');

        Queue::assertPushed(CommanderProvisionPackageJob::class, 3);
        $this->assertSame(3, CommanderClientModel::query()->where('account_id', $accountId)->count());
    }
}
