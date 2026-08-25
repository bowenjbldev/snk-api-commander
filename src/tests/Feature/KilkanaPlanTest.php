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

        // Pasarse del tope se lee de las dos cifras y no de una bandera: el panel decide con
        // ellas si enciende el naranja, y así el umbral vive en un solo sitio.
        $this->assertGreaterThan($seats['limit'], $seats['used']);
        $this->assertSame(0, $this->meter($payload, 'reads_month')['used']);
    }

    public function test_un_tope_puesto_en_la_suscripcion_tambien_llega_y_la_instancia_le_gana(): void
    {
        [$basic] = $this->plans();
        $instance = $this->periodico($basic);
        $subscriptionId = $instance->subscriptionItem->subscription_id;

        // El contrato negociado pone los dos topes arriba…
        $this->quota(null, $subscriptionId, 'editorial_seats', used: 30, limit: 40);
        $this->quota(null, $subscriptionId, 'reads_month', used: 900, limit: 1_000);
        // …y este periódico tiene además el suyo propio para uno de ellos.
        $this->quota($instance->id, null, 'reads_month', used: 120, limit: 500);

        $payload = $this->payloadFor($instance);

        // Sin mirar la suscripción, este medidor saldría vacío: no hay fila de asientos para
        // la instancia. Vacío se dibuja igual que «aún no ha cargado», y por eso no basta.
        $this->assertSame(40, $this->meter($payload, 'editorial_seats')['limit']);

        // Y donde están las dos, gana la de la instancia, que es la más específica.
        $this->assertSame(500, $this->meter($payload, 'reads_month')['limit']);
        $this->assertSame(120, $this->meter($payload, 'reads_month')['used']);
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

    private function periodico(CommanderPlanModel $plan): CommanderInstanceModel
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
