<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packages\Commander\Models\CommanderAccountModel;
use Packages\Commander\Models\CommanderApplicationModel;
use Packages\Commander\Models\CommanderClientModel;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Commander\Models\CommanderSubscriptionItemModel;
use Packages\Commander\Models\CommanderSubscriptionModel;
use Packages\Commander\Services\CommanderApplicationRegistryService;
use Packages\Core\Support\CoreActiveInstanceSupport;
use Packages\Kilkana\Services\SnkKilkanaPlanService;
use Tests\CommanderTestCase;

/**
 * Lo que un periódico puede saber de su propio plan.
 *
 * Se prueba el servicio y no la ruta HTTP por lo mismo que `KilkanaPostsListTest`: montar una
 * petición autenticada contra un inquilino exige un arnés que la suite no tiene. Lo que aquí
 * se fija es lo que puede romperse en silencio —una cuota que vive en la suscripción y no en
 * la instancia deja el medidor vacío sin error— y el escalón siguiente, que es lo que hace
 * verdadero el aviso de banda.
 */
class KilkanaPlanTest extends CommanderTestCase
{
    use RefreshDatabase;

    private CommanderApplicationModel $application;

    protected function setUp(): void
    {
        parent::setUp();

        app(CommanderApplicationRegistryService::class)->sync();
        $this->application = CommanderApplicationModel::where('slug', 'kilkana')->firstOrFail();
    }

    public function test_el_periodico_lee_su_plan_su_consumo_y_el_escalon_siguiente(): void
    {
        [$basic] = $this->plans();
        $instance = $this->periodico($basic);

        $this->quota($instance->id, null, 'editorial_seats', used: 10, limit: 3);
        $this->quota($instance->id, null, 'reads_month', used: 0, limit: 100_000);

        $payload = $this->payloadFor($instance);

        $this->assertSame('Basic', $payload['plan']['name']);
        $this->assertSame(29.0, $payload['plan']['price_monthly']);

        // El aviso de banda dice adónde se sube, y ese nombre no puede estar escrito a mano en
        // el panel: sale del catálogo, que es quien sabe qué escalones hay.
        $this->assertSame(['slug' => 'pro', 'name' => 'Pro'], $payload['plan']['next']);

        $seats = $this->meter($payload, 'editorial_seats');
        $this->assertSame(10, $seats['used']);
        $this->assertSame(3, $seats['limit']);
        $this->assertSame(80, $seats['warning_percent']);

        // Qué pasa al pasarse lo decide el contrato, no el panel: `suspend` apaga la instancia
        // y `recommend` solo recomienda subir. Sin estos dos campos, la tarjeta solo puede
        // escribir «no se corta nada», que es falso justo en el caso que más importa.
        $this->assertSame('recommend', $seats['action']);
        $this->assertSame(2, $seats['consecutive_periods']);
        $this->assertSame('instance', $seats['scope']);

        // El plazo de la gracia es del despliegue, no del inquilino, y sin él el aviso solo
        // puede decir «se suspende» sin decir cuándo.
        $this->assertSame(72, $payload['grace_hours']);

        // El precio del catálogo y el modo, juntos: con `negotiated` la cifra de arriba no es lo
        // que paga esta cabecera, y quien la rotule tiene que poder saberlo.
        $this->assertSame('catalog', $payload['plan']['pricing_mode']);

        // Pasarse del tope se lee de las dos cifras y no de una bandera: el panel decide con
        // ellas si enciende el naranja, y así el umbral vive en un solo sitio.
        $this->assertGreaterThan($seats['limit'], $seats['used']);
        $this->assertSame(0, $this->meter($payload, 'reads_month')['used']);
    }

    public function test_el_tope_propio_y_el_del_contrato_llegan_los_dos_y_no_se_tapan(): void
    {
        [$basic] = $this->plans();
        $instance = $this->periodico($basic);
        $subscriptionId = $instance->subscriptionItem->subscription_id;

        // Así es como sale una cabecera del provisioning: cuota **propia para cada métrica**.
        $this->quota($instance->id, null, 'editorial_seats', used: 2, limit: 10);
        $this->quota($instance->id, null, 'reads_month', used: 120, limit: 500);
        // Y encima, el contrato del grupo, que mide y aplica por su cuenta.
        $this->quota(null, $subscriptionId, 'editorial_seats', used: 30, limit: 40);

        $seats = collect($this->payloadFor($instance)['meters'])
            ->where('key', 'editorial_seats')
            ->keyBy('scope');

        // Deduplicar por métrica pierde siempre la compartida, porque la de instancia existe
        // siempre. Y no son alternativas: este periódico está dentro de su tope (2 de 10) y el
        // grupo está a 30 de 40, que es la cifra que puede apagarle la cabecera.
        $this->assertCount(2, $seats);
        $this->assertSame(10, $seats['instance']['limit']);
        $this->assertSame(40, $seats['subscription']['limit']);
        $this->assertSame(30, $seats['subscription']['used']);
    }

    /**
     * Una métrica retirada deja de medirse y deja de aplicarse —`CommanderQuotaMeasurerService`
     * salta las que no están activas—, así que su `current_value` se queda congelado en la última
     * pasada. Servirla igual pone al panel a informar sobre un tope que ya no acota: lo que se
     * aplica y lo que se informa tienen que decidirse con la misma condición.
     */
    public function test_una_metrica_retirada_deja_de_contarse(): void
    {
        [$basic] = $this->plans();
        $instance = $this->periodico($basic);

        $this->quota($instance->id, null, 'editorial_seats', used: 10, limit: 3);
        $this->quota($instance->id, null, 'reads_month', used: 0, limit: 100_000);
        $this->application->metrics()->where('key', 'reads_month')->update(['status' => 'inactive']);

        $payload = $this->payloadFor($instance);
        $claves = collect($payload['meters'])->pluck('key');

        $this->assertTrue($claves->contains('editorial_seats'));
        $this->assertFalse($claves->contains('reads_month'), 'una métrica retirada no se informa');
    }

    /** Un ítem dado de baja no acota nada: el medidor solo cuenta los activos. */
    public function test_un_item_dado_de_baja_no_rotula_su_plan(): void
    {
        [$basic] = $this->plans();
        $instance = $this->periodico($basic);
        $this->quota($instance->id, null, 'editorial_seats', used: 1, limit: 10);

        $instance->subscriptionItem()->update(['status' => 'canceled']);

        $this->assertNull($this->payloadFor($instance)['plan']);
    }

    public function test_un_tope_ausente_es_sin_tope_y_no_un_cero(): void
    {
        [$basic] = $this->plans();
        $instance = $this->periodico($basic);
        $this->quota($instance->id, null, 'editorial_seats', used: 7, limit: null);

        // Cinco de las siete métricas se miden para verlas, no para acotarlas. Un cero ahí
        // convertiría «sin tope» en «tope agotado», que es el aviso contrario.
        //
        // Y esto es **respuesta válida y no un fallo**, que es lo que su prueba gemela del panel
        // —`plan.server.test.ts`— tiene que afirmar también: dos suites verdes diciendo lo
        // contrario del mismo campo es el modo en que este contrato se rompe sin que nada avise.
        // Es alcanzable por dos caminos legales: un plan sin fila en `commander_plan_limits`
        // para esa métrica, y una cuota de suscripción creada a propósito sin tope.
        $this->assertNull($this->meter($this->payloadFor($instance), 'editorial_seats')['limit']);
    }

    /**
     * Un contrato negociado no tiene precio por cabecera: el importe es del contrato entero y de
     * la cuenta. Rotular el del catálogo ahí es decirle a la redacción una cifra que no paga.
     */
    public function test_un_contrato_negociado_se_declara_como_tal(): void
    {
        [$basic] = $this->plans();
        $instance = $this->periodico($basic, pricingMode: 'negotiated');
        $this->quota($instance->id, null, 'editorial_seats', used: 1, limit: 10);
        $this->quota($instance->id, null, 'reads_month', used: 1, limit: 10);

        $this->assertSame('negotiated', $this->payloadFor($instance)['plan']['pricing_mode']);
    }

    /**
     * Que la ruta **declara** su puerta. Quién pasa por ella lo fija `KilkanaPanelPermissionsTest`
     * sobre el reparto de roles, y hacen falta las dos: esta se queda verde si alguien le da
     * `index posts` a `reader`, y aquella se queda verde si alguien quita el `can:` de la ruta.
     *
     * Lo que ninguna de las dos cubre es que el middleware **se ejecute**: eso pide una petición
     * autenticada contra un inquilino —instancia en Commander, base migrada, resolución por host y
     * token de Sanctum en la base del periódico— y la suite no tiene ese arnés. Ningún test toca
     * hoy una ruta protegida de `api/v1/kilkana`, no solo esta.
     */
    public function test_la_ruta_del_plan_va_tras_el_permiso_del_panel(): void
    {
        $ruta = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => str_ends_with($r->uri(), 'kilkana/plan') && in_array('GET', $r->methods(), true));

        $this->assertNotNull($ruta, 'La ruta GET plan no está registrada.');
        $this->assertContains('can:index posts', $ruta->gatherMiddleware());
        $this->assertContains('auth:sanctum', $ruta->gatherMiddleware());
    }

    public function test_sin_inquilino_no_hay_plan_que_contar(): void
    {
        app(CoreActiveInstanceSupport::class)->forget();

        $this->assertNull(app(SnkKilkanaPlanService::class)->payload());
    }

    /** @return array<int, CommanderPlanModel> */
    private function plans(): array
    {
        return collect([['basic', 'Basic', 29], ['pro', 'Pro', 59], ['business', 'Business', 99]])
            ->map(fn (array $fila) => CommanderPlanModel::create([
                'application_id' => $this->application->id,
                'slug' => $fila[0],
                'name' => $fila[1],
                'price_monthly' => $fila[2],
            ]))
            ->all();
    }

    private function periodico(CommanderPlanModel $plan, string $pricingMode = 'catalog'): CommanderInstanceModel
    {
        $account = CommanderAccountModel::create([
            'name' => 'Grupo Kilkana',
            'email' => 'direccion@grupo.test',
        ]);
        $client = CommanderClientModel::create([
            'account_id' => $account->id,
            'slug' => 'diario-norte',
            'name' => 'Diario Norte',
            'email' => 'redaccion@diario-norte.test',
            'status' => 'active',
        ]);
        $instance = CommanderInstanceModel::create([
            'client_id' => $client->id,
            'application_id' => $this->application->id,
            'database' => 'diario_norte_kilkana',
            'provisioning_status' => 'ready',
        ]);
        $subscription = CommanderSubscriptionModel::create([
            'account_id' => $account->id,
            'application_id' => $this->application->id,
            'name' => 'Kilkana Grupo 2026',
            'pricing_mode' => $pricingMode,
            'status' => 'active',
            'started_at' => now(),
        ]);
        CommanderSubscriptionItemModel::create([
            'subscription_id' => $subscription->id,
            'instance_id' => $instance->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        return $instance->refresh();
    }

    private function quota(?int $instanceId, ?int $subscriptionId, string $key, int $used, ?int $limit): void
    {
        CommanderQuotaModel::create([
            'instance_id' => $instanceId,
            'subscription_id' => $subscriptionId,
            'metric_id' => $this->application->metrics()->where('key', $key)->firstOrFail()->id,
            'current_value' => $used,
            'limit_value' => $limit,
            'warning_percent' => 80,
            'action' => 'recommend',
            'consecutive_periods' => 2,
            'measured_at' => now(),
        ]);
    }

    private function payloadFor(CommanderInstanceModel $instance): array
    {
        app(CoreActiveInstanceSupport::class)->set($instance);

        return app(SnkKilkanaPlanService::class)->payload();
    }

    private function meter(array $payload, string $key): array
    {
        return collect($payload['meters'])->firstOrFail(fn (array $meter) => $meter['key'] === $key);
    }
}
