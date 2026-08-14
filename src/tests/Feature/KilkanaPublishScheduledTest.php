<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderAccountModel;
use Packages\Commander\Models\CommanderApplicationModel;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderInstanceModel;
use Tests\TestCase;

class KilkanaPublishScheduledTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_restores_the_connection_when_a_tenant_fails(): void
    {
        $instance = $this->makeInstance('broken');
        $instance->forceFill([
            'database' => '/missing/kilkana.sqlite',
        ])->save();

        $default = DB::getDefaultConnection();
        $tenantDatabase = config('database.connections.tenant.database');

        $this->artisan('kilkana:publish-scheduled')->assertFailed();

        $this->assertSame($default, DB::getDefaultConnection());
        $this->assertSame($tenantDatabase, config('database.connections.tenant.database'));
    }

    public function test_it_skips_suspended_tenants(): void
    {
        $this->makeInstance('suspended')->forceFill(['service_status' => 'suspended'])->save();

        $this->artisan('kilkana:publish-scheduled')
            ->expectsOutput('Done: 0 post(s) published across 0 tenant(s); 0 failed.')
            ->assertSuccessful();
    }

    private function makeInstance(string $slug): CommanderInstanceModel
    {
        $account = CommanderAccountModel::create(['name' => ucfirst($slug), 'email' => "{$slug}@example.com"]);
        $client = CommanderClientModel::create([
            'account_id' => $account->id,
            'slug' => $slug,
            'name' => ucfirst($slug),
            'email' => "{$slug}@example.com",
        ]);
        $application = CommanderApplicationModel::firstOrCreate(
            ['package_name' => 'Kilkana'],
            ['slug' => 'kilkana', 'name' => 'Kilkana', 'status' => 'active', 'code_status' => 'available'],
        );

        return CommanderInstanceModel::create([
            'client_id' => $client->id,
            'application_id' => $application->id,
            'database' => "{$slug}_kilkana",
            'database_username' => 'tenant_'.$client->id,
            'database_password' => 'runtime-password',
            'service_status' => 'active',
            'provisioning_status' => 'ready',
            'provisioned_at' => now(),
        ]);
    }
}
