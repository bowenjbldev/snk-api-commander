<?php

namespace Packages\Kilkana\Services;

use Illuminate\Support\Collection;
use Packages\Commander\Models\CommanderInstanceModel;
use Packages\Commander\Models\CommanderPlanModel;
use Packages\Commander\Models\CommanderQuotaModel;
use Packages\Core\Support\CoreActiveInstanceSupport;

/**
 * Lo que un periódico puede saber de su propio plan.
 *
 * El dato no es del paquete Kilkana: lo mide Commander, instancia a instancia, y lo guarda
 * en `commander_quotas` con su tope, su umbral y la hora de la medición. Aquí no se vuelve a
 * medir nada — eso lo hace `commander:quota:measure` y hacerlo otra vez daría dos cifras
 * distintas para la misma pregunta. Esto es la ventanilla, no el contador.
 *
 * La instancia sale de `CoreActiveInstanceSupport`, que es donde la dejó
 * `CoreResolveInstanceFromHostMiddleware` al conmutar la conexión. Derivarla otra vez del
 * host sería una segunda manera de responder «quién es este inquilino».
 */
class SnkKilkanaPlanService
{
    public function __construct(private readonly CoreActiveInstanceSupport $active) {}

    /** Null cuando la petición no viene de un inquilino: sin instancia no hay plan que contar. */
    public function payload(): ?array
    {
        $instance = $this->active->current();

        if ($instance === null) {
            return null;
        }

        // **Activo, como lo exige el medidor.** `targetInstanceIds` solo cuenta los ítems con
        // `status = active`, así que un ítem dado de baja ya no acota nada: servir su plan sería
        // rotular un contrato que no se aplica.
        $item = $instance->subscriptionItem()
            ->where('status', 'active')
            ->with(['plan', 'subscription.account'])
            ->first();
        $plan = $item?->plan;
        $quotas = $this->quotas($instance, $item?->subscription_id);

        return [
            'plan' => $plan === null ? null : [
                'slug' => $plan->slug,
                'name' => $plan->name,
                // El precio del CATÁLOGO, que es lo que cuesta ese plan a quien lo compra suelto.
                // Con `pricing_mode = negotiated` **no es lo que paga este periódico**: el importe
                // vive en la suscripción, es del contrato entero y no tiene reparto por cabecera.
                // Quien rotule esto tiene que mirar el modo antes que la cifra.
                'price_monthly' => $plan->price_monthly,
                'pricing_mode' => $item?->subscription?->pricing_mode,
                // A quién le llega la factura, que es lo ÚNICO cierto que se puede decir de un
                // contrato negociado sin inventar un reparto. Las tres cabeceras del mismo
                // contrato tienen planes distintos —Basic, Pro y Business— y sus precios de
                // catálogo suman 187 contra los 180 que se pagan: ni la cifra del catálogo, ni el
                // importe entero, ni ninguna división de él dicen la verdad sobre una sola.
                'account' => $item?->subscription?->account?->name,
                'next' => $this->next($plan),
            ],
            // Cuánto dura la gracia antes de que una cuota con `action = suspend` apague la
            // cabecera. Es configuración del despliegue, no del inquilino, y sin ella el aviso
            // solo puede decir «se suspende» sin decir cuándo.
            'grace_hours' => (int) config('core.quotas.grace_hours', 72),
            'meters' => $quotas->map(fn (CommanderQuotaModel $quota) => [
                'key' => $quota->metric->key,
                // A quién acota esta fila. **No son alternativas**: una cabecera puede tener a la
                // vez su propio tope y el del contrato que comparte con sus hermanas, y las dos se
                // miden y las dos se aplican —una de suscripción que suspende apaga todas las
                // cabeceras del grupo (`targetInstanceIds`)—. Quien las funda en una pierde la que
                // no dibuja, y el provisioning crea SIEMPRE la de instancia, así que la que se
                // perdía era la compartida.
                'scope' => $quota->subscription_id !== null ? 'subscription' : 'instance',
                'name' => $quota->metric->name,
                'unit' => $quota->metric->unit,
                'used' => $quota->current_value,
                // Nulo es «sin tope», que no es lo mismo que cero: cinco de las siete métricas
                // se miden para verlas, no para acotarlas.
                'limit' => $quota->limit_value,
                'warning_percent' => $quota->warning_percent,
                // Qué pasa de verdad al pasarse, que no es lo mismo en todos los contratos:
                // `suspend` apaga la instancia (`CommanderQuotaMeasurerService::enforce`) y
                // `recommend` solo enciende la recomendación de subir. Sin este campo el panel
                // solo puede decir «no pasa nada», que es cierto en tres de los cuatro casos.
                'action' => $quota->action,
                // Cuántos periodos seguidos por encima hacen falta para que esa acción ocurra.
                // Es `settable` de 1 a 12, así que un «dos meses» escrito en el panel es una
                // afirmación sobre un contrato que el panel no ve.
                'consecutive_periods' => $quota->consecutive_periods,
            ])->values()->all(),
            // La MÁS VIEJA de las mediciones, no la más nueva: es la única fecha con la que
            // «todo esto es de las 06:00» sigue siendo cierto de todas las cifras a la vez.
            'measured_at' => $quotas->pluck('measured_at')->filter()->min()?->toIso8601String(),
        ];
    }

    /**
     * Las cuotas que gobiernan a esta cabecera, que son de dos ámbitos y **se suman**.
     *
     * Una fila es de la instancia o de la suscripción —la base lo obliga con un CHECK—, pero eso
     * no las hace alternativas: `commander:quota:measure` mide los dos ámbitos por separado y
     * aplica los dos, y una de suscripción que suspende apaga **todas** las cabeceras activas del
     * contrato (`targetInstanceIds`). Un periódico de un grupo puede estar dentro de su tope y
     * fuera del compartido a la vez.
     *
     * Por eso aquí no se deduplica por métrica. Quien lo hace pierde una de las dos, y siempre la
     * misma: el provisioning crea una cuota de instancia para **cada** métrica activa
     * (`CommanderProvisioningController`), así que la de instancia tapa siempre a la compartida y
     * el contrato de grupo deja de existir para el panel sin que nada falle.
     */
    private function quotas(CommanderInstanceModel $instance, ?int $subscriptionId): Collection
    {
        $de = fn (string $columna, ?int $id) => $id === null
            ? collect()
            : CommanderQuotaModel::query()->with('metric')->where($columna, $id)->get();

        // **Y solo las de métricas vivas.** `CommanderQuotaMeasurerService` salta las inactivas
        // —deja de medirlas y deja de aplicarlas—, así que su `current_value` se queda congelado
        // en la última pasada. Servirlo igual pone al panel a informar sobre un tope que ya no
        // acota: una cifra rancia que solo se dibujaba mal ahora enciende un aviso que promete una
        // suspensión que no puede ocurrir. Lo que se aplica y lo que se informa tienen que
        // decidirse con la misma condición.
        return $de('instance_id', $instance->getKey())
            ->concat($de('subscription_id', $subscriptionId))
            ->filter(fn (CommanderQuotaModel $quota) => $quota->metric?->status === 'active');
    }

    /**
     * El escalón siguiente, que es lo que hace verdadero el aviso de banda.
     *
     * Sin él la tarjeta puede decir que pasarse mueve el plan, pero no adónde, y el rótulo
     * acaba nombrando un plan escrito a mano que un catálogo nuevo desmiente. Nulo en el más
     * caro, que es el que no tiene adónde subir.
     */
    private function next(CommanderPlanModel $plan): ?array
    {
        $siguiente = CommanderPlanModel::query()
            ->where('application_id', $plan->application_id)
            ->where('status', 'active')
            ->where('price_monthly', '>', $plan->price_monthly)
            ->orderBy('price_monthly')
            ->first();

        return $siguiente === null ? null : ['slug' => $siguiente->slug, 'name' => $siguiente->name];
    }
}
