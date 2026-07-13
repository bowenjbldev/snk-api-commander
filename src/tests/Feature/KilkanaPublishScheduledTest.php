<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderPackageModel;
use Tests\TestCase;

class KilkanaPublishScheduledTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_restores_the_connection_when_a_tenant_fails(): void
    {
        $client = CommanderClientModel::create([
            'slug' => 'broken', 'name' => 'Broken', 'email' => 'broken@example.com', 'status' => 'active',
        ]);
        CommanderPackageModel::create([
            'client_id' => $client->id,
            'slug' => 'kilkana',
            'name' => 'Kilkana',
            'database' => '/missing/kilkana.sqlite',
            'status' => 'active',
            'provisioned_at' => now(),
        ]);

        $default = DB::getDefaultConnection();
        $tenantDatabase = config('database.connections.tenant.database');

        $this->artisan('kilkana:publish-scheduled')->assertFailed();

        $this->assertSame($default, DB::getDefaultConnection());
        $this->assertSame($tenantDatabase, config('database.connections.tenant.database'));
    }
}
