import { pct } from '@services/api/mock/commander-mock';
import type { Client, Instance, Quota, Subscription } from '@services/api/types';

export type QuotaHealth = 'nolimit' | 'over' | 'warn' | 'ok';

/** Sin límite no hay porcentaje; sin medición tampoco. Cero no es lo mismo que ninguno. */
export function quotaPercent(quota: Quota): number | null {
  return pct(quota.current_value, quota.limit_value);
}

/**
 * Las tres situaciones que el listado deja filtrar; el resto es holgura y no se rotula.
 * Una cuota sin medición no es ninguna de ellas: `pct` divide un nulo y devuelve cero,
 * así que no llega a ningún umbral.
 */
export function quotaHealth(quota: Quota): QuotaHealth {
  if (quota.limit_value === null) return 'nolimit';
  const percent = quotaPercent(quota) ?? 0;
  // Excedida es estrictamente por encima: al 100 % la cuota está llena, no rebasada.
  if (percent > 100) return 'over';
  return percent >= (quota.warning_percent ?? 80) ? 'warn' : 'ok';
}

/**
 * El dueño de la cuota se nombra por quien la sufre: el cliente que hay detrás de la
 * instancia, o el contrato cuando la cuota es compartida. El identificador crudo es el
 * último recurso, no el nombre.
 */
export function quotaOwnerName(
  quota: Quota,
  instances: Map<number, Instance>,
  clients: Map<number, Client>,
  subscriptions: Map<number, Subscription>,
): string {
  if (quota.scope === 'subscription') {
    return (
      subscriptions.get(quota.subscription_id ?? -1)?.name ?? `Contrato ${quota.subscription_id}`
    );
  }
  const instance = instances.get(quota.instance_id ?? -1);
  const client = instance ? clients.get(instance.client_id) : undefined;
  return client?.name ?? `Instancia ${quota.instance_id}`;
}

/** Una cuota no tiene ficha de dueño: vive en la pestaña de cuotas de quien la paga. */
export function quotaOwnerHref(quota: Quota): string {
  return quota.scope === 'instance'
    ? `/instances/${quota.instance_id}/quotas`
    : `/subscriptions/${quota.subscription_id}/quotas`;
}

/**
 * La cuota más apretada de cada instancia: es el primer sitio donde mira un
 * operador, así que se calcula una vez y se consulta por fila.
 */
export function worstQuotaByInstance(quotas: Quota[]): Map<number, Quota> {
  const map = new Map<number, Quota>();
  for (const q of quotas) {
    if (q.scope !== 'instance' || q.instance_id === null) continue;
    const current = map.get(q.instance_id);
    if (!current || (quotaPercent(q) ?? -1) > (quotaPercent(current) ?? -1)) {
      map.set(q.instance_id, q);
    }
  }
  return map;
}

export function isExceeded(quota: Quota): boolean {
  return quota.limit_value !== null && (quota.current_value ?? 0) > quota.limit_value;
}
