import type { Invoice, InvoiceLine, Quota, Subscription, SubscriptionItem } from '@services/api/types';

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

/** Una línea de factura no tiene PK propia: su identidad es la factura y su posición. */
export interface SubscriptionInvoiceLine {
  id: string;
  invoice: Invoice;
  line: InvoiceLine;
}

/**
 * Quien referencia el contrato es la línea, no la factura: una misma factura puede
 * cobrar varios contratos de la cuenta y solo una de sus líneas apunta a este.
 */
export function linesReferencing(invoices: Invoice[], subscriptionId: number): SubscriptionInvoiceLine[] {
  const out: SubscriptionInvoiceLine[] = [];
  for (const invoice of invoices) {
    invoice.lines.forEach((line, index) => {
      if (line.subscription_id === subscriptionId) out.push({ id: `${invoice.id}-${index}`, invoice, line });
    });
  }
  return out;
}

/**
 * Cancelar, no borrar: un contrato con cabeceras, cuotas o líneas facturadas es
 * historia contable y la API tampoco expone borrado.
 */
export function isSubscriptionDeletable(
  items: SubscriptionItem[],
  quotas: Quota[],
  lines: SubscriptionInvoiceLine[],
): boolean {
  return items.length === 0 && quotas.length === 0 && lines.length === 0;
}
