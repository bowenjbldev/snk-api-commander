import { pct } from '@services/api/mock/commander-mock';
import type { Quota } from '@services/api/types';

export type QuotaHealth = 'over' | 'warn' | 'ok' | 'unmeasured' | 'nolimit';

/** Sin límite no hay porcentaje; sin medición tampoco. Cero no es lo mismo que ninguno. */
export function quotaPercent(quota: Quota): number | null {
  return pct(quota.current_value, quota.limit_value);
}

export function quotaHealth(quota: Quota): QuotaHealth {
  if (quota.limit_value === null) return 'nolimit';
  if (quota.current_value === null) return 'unmeasured';
  const percent = quotaPercent(quota) ?? 0;
  if (percent >= 100) return 'over';
  return percent >= (quota.warning_percent ?? 80) ? 'warn' : 'ok';
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
