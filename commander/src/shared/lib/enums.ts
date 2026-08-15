import type { EntityStatus, InvoiceStatus, QuotaAction, SubscriptionStatus } from '@services/api/types';

/**
 * Un `<select>` devuelve `string`. Estrechar aquí, en un sitio, evita repartir
 * castings por los componentes: si llega un valor que la API no conoce, se cae al
 * valor por defecto en vez de viajar hacia el servidor.
 */
function narrow<T extends string>(value: string, allowed: readonly T[], fallback: T): T {
  return (allowed as readonly string[]).includes(value) ? (value as T) : fallback;
}

const ENTITY_STATUSES = ['active', 'inactive', 'suspended'] as const;
const SUBSCRIPTION_STATUSES = ['trialing', 'active', 'past_due', 'canceled'] as const;
const INVOICE_STATUSES = ['draft', 'pending', 'paid', 'overdue', 'void'] as const;
const QUOTA_ACTIONS = ['none', 'warn', 'suspend', 'recommend'] as const;

export const asEntityStatus = (v: string): EntityStatus => narrow(v, ENTITY_STATUSES, 'active');
export const asSubscriptionStatus = (v: string): SubscriptionStatus => narrow(v, SUBSCRIPTION_STATUSES, 'active');
export const asInvoiceStatus = (v: string): InvoiceStatus => narrow(v, INVOICE_STATUSES, 'draft');
export const asQuotaAction = (v: string): QuotaAction => narrow(v, QUOTA_ACTIONS, 'warn');
