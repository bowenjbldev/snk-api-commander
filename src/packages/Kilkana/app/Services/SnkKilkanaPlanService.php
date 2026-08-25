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

        $item = $instance->subscriptionItem()->with('plan')->first();
        $plan = $item?->plan;
        $quotas = $this->quotas($instance, $item?->subscription_id);

        return [
            'plan' => $plan === null ? null : [
                'slug' => $plan->slug,
                'name' => $plan->name,
                'price_monthly' => $plan->price_monthly,
                'next' => $this->next($plan),
            ],
            'meters' => $quotas->map(fn (CommanderQuotaModel $quota) => [
                'key' => $quota->metric->key,
                'name' => $quota->metric->name,
                'unit' => $quota->metric->unit,
                'used' => $quota->current_value,
                // Nulo es «sin tope», que no es lo mismo que cero: cinco de las siete métricas
                // se miden para verlas, no para acotarlas.
                'limit' => $quota->limit_value,
                'warning_percent' => $quota->warning_percent,
            ])->values()->all(),
            // La MÁS VIEJA de las mediciones, no la más nueva: es la única fecha con la que
            // «todo esto es de las 06:00» sigue siendo cierto de todas las cifras a la vez.
            'measured_at' => $quotas->pluck('measured_at')->filter()->min()?->toIso8601String(),
        ];
    }

    /**
     * Las cuotas que gobiernan a esta instancia, que pueden estar en dos sitios.
     *
     * Una cuota es de la instancia **o** de la suscripción —la base lo obliga con un CHECK—,
     * y un contrato negociado para tres periódicos pone el tope arriba, en la suscripción. Con
     * mirar solo `instance_id` el medidor sale vacío el día que alguien mueve el tope, y sin
     * error: una lista vacía se dibuja igual que una que aún no ha cargado.
     *
     * Cuando la misma métrica está en los dos sitios gana la de la instancia, que es la más
     * específica.
     */
    private function quotas(CommanderInstanceModel $instance, ?int $subscriptionId): Collection
    {
        $de = fn (string $columna, ?int $id) => $id === null
            ? collect()
            : CommanderQuotaModel::query()->with('metric')->where($columna, $id)->get();

        // Encadenar y **luego** indexar, en vez de fusionar dos colecciones ya indexadas: la de
        // la instancia va después y `keyBy` se queda con la última de cada métrica. `merge` no
        // sirve para esto por dos caminos distintos —el de Eloquent deduplica por clave primaria
        // del modelo, y el de base es `array_merge`, que RENUMERA las claves enteras—, y en los
        // dos casos sobreviven las dos filas y gana la equivocada. Sin decirlo.
        return $de('subscription_id', $subscriptionId)
            ->concat($de('instance_id', $instance->getKey()))
            ->keyBy('metric_id')
            ->filter(fn (CommanderQuotaModel $quota) => $quota->metric !== null);
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
