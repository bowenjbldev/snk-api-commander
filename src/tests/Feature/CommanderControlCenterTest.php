<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Packages\Commander\Database\Seeders\CommanderDatabaseSeeder;
use Packages\Commander\Models\CommanderActivityEventModel;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderPackageModel;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Commander\Models\CommanderRequestLogModel;
use Packages\Commander\Models\CommanderSubscriptionModel;
use Packages\Commander\Models\CommanderUsageRollupModel;
use Packages\Commander\Services\CommanderQuotaMeasurerService;
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

    /** Provisiona la instancia viva (1:1): la fila commander_packages ES la entidad operativa. */
    private function package(): CommanderPackageModel
    {
        $client = CommanderClientModel::create(['slug' => 'c', 'name' => 'C', 'email' => 'c@c.test', 'status' => 'active']);

        return CommanderPackageModel::create([
            'name' => 'Shop', 'label' => 'Shop', 'listing_status' => 'active',
            'slug' => 'shop', 'database' => 'c_shop', 'status' => 'active',
            'client_id' => $client->id, 'changed_by' => 'operator', 'changed_at' => now(),
            'provisioned_at' => now(),
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

    public function test_quota_update_persists_limit_and_policy_for_enforcement(): void
    {
        $admin = $this->admin();
        $pkg = $this->package();

        // Tabla unica: la fila que edita el panel ES la que mide/aplica el motor (sin propagacion).
        $quota = CommanderQuotaModel::create([
            'package_id' => $pkg->id, 'metric_key' => 'requests_day',
            'current_value' => 0, 'limit_value' => 1000, 'on_exceed' => 'warn', 'unit' => 'count',
        ]);

        $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/quotas/{$quota->id}", [
            'limit_value' => 500, 'on_exceed' => 'suspend',
        ])->assertOk();

        $fresh = CommanderQuotaModel::find($quota->id);
        $this->assertSame(500, (int) $fresh->limit_value);
        $this->assertSame('suspend', $fresh->on_exceed);
    }

    public function test_quota_enforcement_records_activity_event(): void
    {
        $admin = $this->admin();
        $pkg = $this->package(); // package Shop (subdomain shop, active)
        $pid = $pkg->id;

        // Cuota requests_day con limite 5 / suspend; y 8 logs hoy => supera el limite.
        CommanderQuotaModel::create([
            'package_id' => $pid, 'metric_key' => 'requests_day',
            'limit_value' => 5, 'current_value' => 0, 'on_exceed' => 'suspend', 'unit' => 'count',
        ]);
        for ($i = 0; $i < 8; $i++) {
            CommanderRequestLogModel::create([
                'package_id' => $pid, 'occurred_at' => now(), 'method' => 'GET',
                'route' => '/api/v1/x', 'path' => 'api/v1/x', 'status' => 200,
                'duration_ms' => 1, 'request_bytes' => 0, 'response_bytes' => 1, 'memory_kb' => 1,
            ]);
        }

        app(CommanderQuotaMeasurerService::class)->measureAll();

        // El medidor lo movio a grace…
        $this->assertSame('grace', CommanderPackageModel::find($pid)->getRawOriginal('status'));

        // …y dejo rastro en el feed de actividad con by='quota' (antes no se registraba).
        $event = CommanderActivityEventModel::where('package_id', $pid)->where('by', 'quota')->first();
        $this->assertNotNull($event);
        $this->assertSame('grace', $event->kind);
        $this->assertSame('quota_exceeded:requests_day', $event->reason);
    }

    public function test_global_logs_tag_each_row_with_package(): void
    {
        $admin = $this->admin();
        $pkg = $this->package();

        CommanderRequestLogModel::create([
            'package_id' => $pkg->id, 'occurred_at' => now(), 'method' => 'GET',
            'route' => '/api/v1/products', 'path' => 'api/v1/products', 'status' => 200,
            'duration_ms' => 7, 'user_id' => null, 'token_id' => 'tok_x', 'ip' => '127.0.0.1',
            'request_bytes' => 0, 'response_bytes' => 12, 'memory_kb' => 2,
        ]);

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/logs')->assertOk();
        $res->assertJsonCount(1, 'data');
        // Cada fila se etiqueta con el name (StudlyCase), el slug, y el subdominio = slug del CLIENTE.
        $this->assertSame('Shop', $res->json('data.0.package'));
        $this->assertSame('shop', $res->json('data.0.slug'));
        $this->assertSame('c', $res->json('data.0.subdomain')); // subdominio ES el del cliente
        $this->assertSame(200, $res->json('data.0.status'));
    }

    public function test_platform_usage_aggregates_rollups_by_bucket(): void
    {
        $admin = $this->admin();
        $pkg = $this->package();
        $bucket = CarbonImmutable::now('UTC')->startOfHour();

        // Dos rollups en el mismo bucket (distinta ruta/clase) -> /usage/platform debe sumarlos.
        CommanderUsageRollupModel::create([
            'package_id' => $pkg->id, 'period' => 'hour', 'period_start' => $bucket,
            'route' => '/a', 'method' => 'GET', 'status_class' => '2xx',
            'request_count' => 80, 'error_count' => 0, 'avg_duration_ms' => 10, 'p95_duration_ms' => 20,
        ]);
        CommanderUsageRollupModel::create([
            'package_id' => $pkg->id, 'period' => 'hour', 'period_start' => $bucket,
            'route' => '/a', 'method' => 'GET', 'status_class' => '5xx',
            'request_count' => 20, 'error_count' => 20, 'avg_duration_ms' => 30, 'p95_duration_ms' => 50,
        ]);

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/usage/platform?period=hour&hours=48')->assertOk();
        $res->assertJsonCount(1, 'data');
        $this->assertSame($pkg->id, $res->json('data.0.package_id'));
        $this->assertSame(100, (int) $res->json('data.0.request_count'));
        $this->assertSame(20, (int) $res->json('data.0.error_count'));
    }

    public function test_plan_rejects_package_without_code_on_disk(): void
    {
        $admin = $this->admin();

        // Ni 'Ghostpkg' (inexistente) ni 'Commander' (framework, no per-cliente) son packages de
        // negocio desplegados bajo algún cliente -> el catálogo no acepta un plan para ellos.
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/plans', [
            'slug' => 'ghost-basic', 'package' => 'Ghostpkg', 'name' => 'Basic', 'price_monthly' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors('package');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/plans', [
            'slug' => 'cmd-basic', 'package' => 'Commander', 'name' => 'Basic', 'price_monthly' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors('package');

        // 'Shop' SÍ existe bajo un cliente (packages/Acme/Shop) -> pasa el guard de package desplegado.
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/plans', [
            'slug' => 'shop-basic', 'package' => 'Shop', 'name' => 'Basic', 'price_monthly' => 10,
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
            $this->assertArrayHasKey('listing_status', $p);
            $this->assertArrayHasKey('on_disk', $p);
            $this->assertArrayHasKey('plan_count', $p);
            $this->assertArrayHasKey('provisioned_at', $p);
        }
    }

    public function test_package_metadata_persists(): void
    {
        $admin = $this->admin();
        CommanderPackageModel::create(['name' => 'Shop', 'label' => 'Shop', 'listing_status' => 'active']);

        // Los metadatos de catalogo (label/description/listing_status) se editan sobre la fila unica.
        $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/packages/Shop', [
            'label' => 'Tienda', 'description' => 'Mi tienda online', 'listing_status' => 'inactive',
        ])->assertOk()->assertJsonPath('label', 'Tienda')->assertJsonPath('listing_status', 'inactive');

        $row = CommanderPackageModel::where('name', 'Shop')->first();
        $this->assertSame('Tienda', $row->label);
        $this->assertSame('inactive', $row->listing_status);
    }

    // ── change-plan (historial 1:N de subscripciones) ──────────────────────────────

    private function planFor(string $package, string $name, float $price = 100, string $status = 'active'): CommanderPlanModel
    {
        return CommanderPlanModel::create([
            'slug' => Str::slug("{$package} {$name}") . '-' . uniqid(),
            'package' => $package, 'name' => $name, 'price_monthly' => $price, 'status' => $status,
        ]);
    }

    private function currentTerm(CommanderPackageModel $pkg, CommanderPlanModel $plan, bool $autoSuspend = true): CommanderSubscriptionModel
    {
        $sub = CommanderSubscriptionModel::create([
            'client_id' => $pkg->client_id, 'package_id' => $pkg->id, 'plan_id' => $plan->id,
            'plan' => $plan->name, 'price_monthly' => $plan->price_monthly,
            'billing_status' => 'paid', 'auto_suspend' => $autoSuspend, 'started_at' => now(), 'ended_at' => null,
        ]);
        $pkg->forceFill(['subscription_id' => $sub->id])->save();

        return $sub;
    }

    public function test_change_plan_closes_current_term_and_opens_new(): void
    {
        $admin = $this->admin();
        $pkg = $this->package();
        $basic = $this->planFor('Shop', 'Basic', 50);
        $pro = $this->planFor('Shop', 'Pro', 290);
        $current = $this->currentTerm($pkg, $basic, true);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/packages/Shop/change-plan', ['plan_id' => $pro->id])
            ->assertOk()
            ->assertJsonPath('subscription.plan', 'Pro');

        // El término anterior queda cerrado; el nuevo es el único vigente y snapshotea el plan elegido.
        $this->assertNotNull($current->fresh()->ended_at);
        $open = CommanderSubscriptionModel::where('package_id', $pkg->id)->whereNull('ended_at')->get();
        $this->assertCount(1, $open);
        $new = $open->first();
        $this->assertSame('Pro', $new->plan);
        $this->assertSame($pro->id, $new->plan_id);
        $this->assertSame(290.0, (float) $new->price_monthly);
        $this->assertSame('pending', $new->billing_status);
        $this->assertTrue((bool) $new->auto_suspend); // heredado del término anterior
        $this->assertSame($new->id, $pkg->fresh()->subscription_id); // package repunta al vigente

        $this->assertNotNull(
            CommanderActivityEventModel::where('package_id', $pkg->id)->where('kind', 'change_plan')->first()
        );
    }

    public function test_change_plan_rejects_plan_of_other_package(): void
    {
        $admin = $this->admin();
        $pkg = $this->package();
        $this->currentTerm($pkg, $this->planFor('Shop', 'Basic', 50));
        $other = $this->planFor('Blog', 'Pro', 100);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/packages/Shop/change-plan', ['plan_id' => $other->id])
            ->assertStatus(422);
    }

    public function test_change_plan_rejects_inactive_plan(): void
    {
        $admin = $this->admin();
        $pkg = $this->package();
        $this->currentTerm($pkg, $this->planFor('Shop', 'Basic', 50));
        $inactive = $this->planFor('Shop', 'Old', 100, 'inactive');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/packages/Shop/change-plan', ['plan_id' => $inactive->id])
            ->assertStatus(422);
    }

    public function test_change_plan_rejects_same_plan(): void
    {
        $admin = $this->admin();
        $pkg = $this->package();
        $basic = $this->planFor('Shop', 'Basic', 50);
        $this->currentTerm($pkg, $basic);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/packages/Shop/change-plan', ['plan_id' => $basic->id])
            ->assertStatus(422);
    }

    public function test_change_plan_requires_provisioned_package(): void
    {
        $admin = $this->admin();
        // Fila solo-catálogo (sin provisioned_at): no es instancia viva.
        CommanderPackageModel::create(['name' => 'Ghost', 'label' => 'Ghost', 'listing_status' => 'active']);
        $plan = $this->planFor('Ghost', 'Pro', 100);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/packages/Ghost/change-plan', ['plan_id' => $plan->id])
            ->assertStatus(404);
    }

    public function test_change_plan_requires_control_permission(): void
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(CommanderDatabaseSeeder::class);
        $pkg = $this->package();
        $plan = $this->planFor('Shop', 'Pro', 100);
        $user = CoreUserModel::create([
            'email' => 'noperm@snake.com', 'name' => 'No Perm', 'full_name' => 'No Perm', 'password' => 'secret1234',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/packages/Shop/change-plan', ['plan_id' => $plan->id])
            ->assertStatus(403);
    }
}
