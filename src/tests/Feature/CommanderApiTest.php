<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packages\Commander\Database\Seeders\CommanderDatabaseSeeder;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Commander\Models\CommanderSubscriptionModel;
use Packages\Core\Database\Seeders\CoreDatabaseSeeder;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Core\Models\CoreUserModel;
use Tests\CommanderTestCase;

class CommanderApiTest extends CommanderTestCase
{
    use RefreshDatabase;

    private function admin(): CoreUserModel
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(CommanderDatabaseSeeder::class);

        return CoreUserModel::where('email', 'admin@snake.com')->firstOrFail();
    }

    public function test_registry_requires_authentication(): void
    {
        $this->getJson('/api/v1/packages')->assertStatus(401);
    }

    public function test_registry_starts_with_no_provisioned_package_then_lists_created_records(): void
    {
        $admin = $this->admin();

        // Demo-free seeder: la tabla puede listar packages de disco (solo-catalogo), pero NINGUNO esta
        // provisionado (provisioned_at == null en todos) y no hay clientes.
        $packages = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/packages')->assertOk()->json();
        $this->assertCount(0, array_filter($packages, fn ($p) => ($p['provisioned_at'] ?? null) !== null));
        $this->assertCount(0, $this->actingAs($admin, 'sanctum')->getJson('/api/v1/clients')->json());

        CommanderClientModel::create([
            'slug' => 'acme', 'name' => 'Acme', 'company' => 'Acme', 'email' => 'ops@acme.test', 'status' => 'active',
        ]);

        $this->assertCount(1, $this->actingAs($admin, 'sanctum')->getJson('/api/v1/clients')->json());
    }

    public function test_package_shape_matches_spa_types(): void
    {
        $admin = $this->admin();

        $client = CommanderClientModel::create([
            'slug' => 'kestrel', 'name' => 'Kestrel Logistics', 'company' => 'Kestrel Logistics Co',
            'email' => 'it@kestrel.test', 'status' => 'active',
        ]);

        // Instancia viva 1:1 por (cliente, package): el subdominio ES el del cliente (kestrel), el slug
        // del package va en el path. Plana (provisioned_at no nulo).
        $package = CommanderPackageModel::create([
            'name' => 'TrackingApi', 'label' => 'TrackingApi', 'listing_status' => 'active',
            'slug' => 'tracking-api', 'database' => 'kestrel_tracking_api',
            'client_id' => $client->id, 'status' => 'grace',
            'reason' => 'payment_overdue', 'message' => null,
            'grace_until' => now()->addHours(48), 'changed_at' => now(),
            'provisioned_at' => now(),
        ]);

        foreach (['requests_day', 'disk_db_mb', 'disk_storage_mb', 'users_total'] as $metric) {
            CommanderQuotaModel::create([
                'package_id' => $package->id, 'metric_key' => $metric,
                'current_value' => 1, 'limit_value' => 100, 'on_exceed' => 'none',
                'unit' => str_ends_with($metric, '_mb') ? 'mb' : 'count',
            ]);
        }

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/packages/TrackingApi');
        $res->assertOk();
        $body = $res->json();

        // La fila unificada llega plana (identidad + estado + relaciones), no anidada bajo `package`.
        $this->assertSame('TrackingApi', $body['name']);
        $this->assertSame('tracking-api', $body['slug']);            // slug del package (path)
        $this->assertSame('kestrel', $body['client']['slug']);      // el subdominio ES el del cliente
        $this->assertSame('grace', $body['status']);
        $this->assertSame('payment_overdue', $body['reason']);
        $this->assertNotNull($body['grace_until']);
        $this->assertNotNull($body['provisioned_at']);

        // Relations resolve.
        $this->assertSame('Kestrel Logistics', $body['client']['name']);
        $this->assertCount(4, $body['quotas']);
    }

    public function test_state_actions_write_packages_in_process(): void
    {
        $admin = $this->admin();

        $client = CommanderClientModel::create(['slug' => 'c', 'name' => 'C', 'email' => 'c@c.test', 'status' => 'active']);
        $package = CommanderPackageModel::create([
            'name' => 'Shop', 'label' => 'Shop', 'listing_status' => 'active',
            'slug' => 'shop', 'database' => 'c_shop',
            'client_id' => $client->id, 'status' => 'active', 'provisioned_at' => now(),
        ]);

        // Suspend → writes the commander_packages row directly (addressed by name); response is the flat row.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/packages/Shop/suspend', ['reason' => 'payment_overdue', 'message' => 'pay up'])
            ->assertOk()
            ->assertJsonPath('status', 'suspended')
            ->assertJsonPath('reason', 'payment_overdue');
        $this->assertSame('suspended', $package->fresh()->getRawOriginal('status'));

        // Resume → back to active.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/packages/Shop/resume')
            ->assertOk()
            ->assertJsonPath('status', 'active');
        $this->assertSame('active', $package->fresh()->getRawOriginal('status'));
    }

    public function test_deprovision_is_soft_and_clears_operational_rows(): void
    {
        $admin = $this->admin();

        $client = CommanderClientModel::create(['slug' => 'c', 'name' => 'C', 'email' => 'c@c.test', 'status' => 'active']);
        $package = CommanderPackageModel::create([
            'name' => 'Shop', 'label' => 'Shop', 'listing_status' => 'active',
            'slug' => 'shop', 'database' => 'c_shop',
            'client_id' => $client->id, 'status' => 'active', 'provisioned_at' => now(),
        ]);
        CommanderQuotaModel::create([
            'package_id' => $package->id, 'metric_key' => 'requests_day',
            'current_value' => 0, 'limit_value' => 100, 'on_exceed' => 'suspend', 'unit' => 'count',
        ]);
        CommanderRequestLogModel::create([
            'package_id' => $package->id, 'created_at' => now(), 'method' => 'GET',
            'route' => '/api/v1/x', 'path' => 'api/v1/x', 'status' => 200,
            'duration_ms' => 1, 'request_bytes' => 0, 'response_bytes' => 1, 'memory_kb' => 1,
        ]);
        // Término de subscripción vigente (historial de billing): debe conservarse pero quedar cerrado.
        $sub = CommanderSubscriptionModel::create([
            'client_id' => $client->id, 'package_id' => $package->id, 'plan_id' => null,
            'plan' => 'Pro', 'price_monthly' => 99, 'billing_status' => 'paid',
            'auto_suspend' => false, 'started_at' => now(), 'ended_at' => null,
        ]);
        $package->forceFill(['subscription_id' => $sub->id])->save();

        // De-provision SOFT: en MariaDB hace DROP DATABASE; borra metering + cuotas y ANULA las columnas
        // operativas, pero conserva la fila (catalogo) y el historial de billing (cierra el término).
        $this->actingAs($admin, 'sanctum')->deleteJson('/api/v1/packages/Shop')->assertOk();

        $fresh = CommanderPackageModel::find($package->id);
        $this->assertNotNull($fresh);                 // la fila persiste (catalogo)
        $this->assertNull($fresh->provisioned_at);    // ya no es instancia viva
        $this->assertNull($fresh->slug);              // identidad (client_id, slug) liberada
        $this->assertNull($fresh->getRawOriginal('client_id'));
        $this->assertNull($fresh->getRawOriginal('subscription_id'));
        $this->assertSame('Shop', $fresh->label);     // catalogo conservado

        $this->assertCount(0, CommanderRequestLogModel::where('package_id', $package->id)->get());
        $this->assertCount(0, CommanderQuotaModel::where('package_id', $package->id)->get());
        // El término de subscripción se conserva (historial) pero queda cerrado.
        $closed = CommanderSubscriptionModel::find($sub->id);
        $this->assertNotNull($closed);
        $this->assertNotNull($closed->ended_at);
    }

    // Nota: el aislamiento operador↔tenant ya no necesita middleware: con BD por package, el token
    // de un tenant vive en SU BD y no existe en la commander -> 401 automatico. Se verifica en vivo
    // (no se puede modelar con una sola sqlite en tests).
}
