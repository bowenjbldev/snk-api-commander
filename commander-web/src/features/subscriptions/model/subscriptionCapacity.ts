import type { Invoice, Quota, Subscription, SubscriptionItem } from '@services/api/types';

/** Cabeceras activas por contrato: es lo que consume capacidad. */
export function activeItemsBySubscription(items: SubscriptionItem[]): Map<number, number> {
  const map = new Map<number, number>();
  for (const it of items) {
    if (it.status !== 'active') continue;
    map.set(it.subscription_id, (map.get(it.subscription_id) ?? 0) + 1);
  }
  return map;
}

export function isCapacityFull(subscription: Subscription, activeItems: number): boolean {
  return activeItems >= subscription.max_instances;
}

/**
 * Cancelar, no borrar: un contrato con cabeceras, cuotas o facturas es historia
 * contable y la API tampoco expone borrado.
 */
export function isSubscriptionDeletable(items: SubscriptionItem[], quotas: Quota[], invoices: Invoice[]): boolean {
  return items.length === 0 && quotas.length === 0 && invoices.length === 0;
}

export function invoicesReferencing(invoices: Invoice[], subscriptionId: number): Invoice[] {
  return invoices.filter((i) => i.lines.some((l) => l.subscription_id === subscriptionId));
}
