<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packages\Commander\Database\Seeders\CommanderDatabaseSeeder;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderDeploymentModel;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Commander\Models\CommanderQuotaMetricModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Commander\Models\CommanderRegisteredPackageModel;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Core\Database\Seeders\CoreDatabaseSeeder;
use Packages\Core\Models\CoreUserModel;
use Tests\CommanderTestCase;

/**
 * Cobertura de las piezas nuevas que adaptan el SPA al backend: settings persistidos,
 * propagacion de la edicion de cuotas al enforcement real, logs globales y validacion
 * de package desplegado en planes.
 */
class CommanderControlCenterTest extends CommanderTestCase
{
    use RefreshDatabase;

    private function admin(): CoreUserModel
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(CommanderDatabaseSeeder::class);

        return CoreUserModel::where('email', 'admin@snake.com')->firstOrFail();
    }

    private function deployment(): CommanderDeploymentModel
    {
        $package = CommanderPackageModel::create([
            'name' => 'Shop', 'subdomain' => 'shop', 'database' => 'shop', 'status' => 'active',
            'changed_by' => 'operator', 'changed_at' => now(),
        ]);
        $client = CommanderClientModel::create(['slug' => 'c', 'name' => 'C', 'email' => 'c@c.test', 'status' => 'active']);

        return CommanderDeploymentModel::create([
            'client_id' => $client->id, 'package_id' => $package->id,
        ]);
    }

    public function test_settings_singleton_defaults_from_config_then_persists(): void
    {
        $admin = $this->admin();
        config(['core.quotas.grace_hours' => 96]);

        // GET crea el singleton sembrando el grace por defecto desde la config de Core.
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('default_grace_hours', 96)
            ->assertJsonPath('notify_on_suspend', true);

        // PUT persiste el perfil + preferencias.
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/settings', [
            'operator_name' => 'Jorge', 'operator_email' => 'ops@snake.test',
            'default_grace_hours' => 120, 'notify_on_overdue' => false,
        ])->assertOk()->assertJsonPath('operator_name', 'Jorge');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('operator_name', 'Jorge')
            ->assertJsonPath('default_grace_hours', 120)
            ->assertJsonPath('notify_on_overdue', false);
    }

    public function test_quota_update_propagates_to_enforcement_metric(): void
    {
        $admin = $this->admin();
        $dep = $this->deployment();

        // Espejo (commander_quotas) + metrica real (commander_quota_metrics) por package_id.
        $quota = CommanderQuotaModel::create([
            'deployment_id' => $dep->id, 'metric_key' => 'requests_day',
            'current_value' => 0, 'limit_value' => 1000, 'on_exceed' => 'warn', 'unit' => 'count',
        ]);
        CommanderQuotaMetricModel::create([
            'package_id' => $dep->package_id, 'metric_key' => 'requests_day',
            'limit_value' => 1000, 'current_value' => 0, 'on_exceed' => 'warn',
        ]);

        $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/quotas/{$quota->id}", [
            'limit_value' => 500, 'on_exceed' => 'suspend',
        ])->assertOk();

        // El medidor solo mira commander_quota_metrics: la edicion del espejo debe haber llegado ahi.
        $metric = CommanderQuotaMetricModel::where('package_id', $dep->package_id)
            ->where('metric_key', 'requests_day')->first();
        $this->assertSame(500, (int) $metric->limit_value);
        $this->assertSame('suspend', $metric->on_exceed);
    }

    public function test_global_logs_tag_each_row_with_deployment_id(): void
    {
        $admin = $this->admin();
        $dep = $this->deployment();

        CommanderRequestLogModel::create([
            'package_id' => $dep->package_id, 'occurred_at' => now(), 'method' => 'GET',
            'route' => '/api/v1/products', 'path' => 'api/v1/products', 'status' => 200,
            'duration_ms' => 7, 'user_id' => null, 'token_id' => 'tok_x', 'ip' => '127.0.0.1',
            'request_bytes' => 0, 'response_bytes' => 12, 'memory_kb' => 2,
        ]);

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/logs')->assertOk();
        $res->assertJsonCount(1, 'data');
        $this->assertSame('shop', $res->json('data.0.deployment_id')); // tag = slug legible
        $this->assertSame(200, $res->json('data.0.status'));
    }

    public function test_plan_rejects_package_without_code_on_disk(): void
    {
        $admin = $this->admin();

        // 'Ghostpkg' no existe en packages/: el catalogo no debe aceptar un plan para el.
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/plans', [
            'slug' => 'ghost-basic', 'package' => 'Ghostpkg', 'name' => 'Basic', 'price_monthly' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors('package');

        // 'Commander' si existe en disco -> pasa el guard de package desplegado.
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/plans', [
            'slug' => 'cmd-basic', 'package' => 'Commander', 'name' => 'Basic', 'price_monthly' => 10,
        ])->assertStatus(201);
    }

    public function test_packages_registry_lists_enriched_and_excludes_core_commander(): void
    {
        $admin = $this->admin();

        // index() sincroniza desde disco y devuelve filas de registro enriquecidas (array, no {data}).
        $packages = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/packages')->assertOk()->json();
        $this->assertIsArray($packages);

        $names = array_column($packages, 'name');
        $this->assertNotContains('Core', $names);
        $this->assertNotContains('Commander', $names);

        foreach ($packages as $p) {
            $this->assertArrayHasKey('status', $p);
            $this->assertArrayHasKey('on_disk', $p);
            $this->assertArrayHasKey('plan_count', $p);
            $this->assertArrayHasKey('has_deployment', $p);
        }
    }

    public function test_package_metadata_persists(): void
    {
        $admin = $this->admin();
        CommanderRegisteredPackageModel::create(['name' => 'Shop', 'label' => 'Shop', 'status' => 'active']);

        $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/packages/Shop', [
            'label' => 'Tienda', 'description' => 'Mi tienda online', 'status' => 'inactive',
        ])->assertOk()->assertJsonPath('label', 'Tienda')->assertJsonPath('status', 'inactive');

        $row = CommanderRegisteredPackageModel::where('name', 'Shop')->first();
        $this->assertSame('Tienda', $row->label);
        $this->assertSame('inactive', $row->status);
    }
}
