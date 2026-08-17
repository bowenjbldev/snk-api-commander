import type { Client, Instance, Invoice, Subscription } from '@services/api/types';

export interface AccountCounts {
  clients: number;
  subscriptions: number;
}

/** Conteos por cuenta en una pasada; la tabla los consulta por fila. */
export function countByAccount(clients: Client[], subscriptions: Subscription[]): Map<number, AccountCounts> {
  const map = new Map<number, AccountCounts>();
  const bump = (id: number, key: keyof AccountCounts) => {
    const row = map.get(id) ?? { clients: 0, subscriptions: 0 };
    row[key] += 1;
    map.set(id, row);
  };
  for (const c of clients) bump(c.account_id, 'clients');
  for (const s of subscriptions) bump(s.account_id, 'subscriptions');
  return map;
}

/** MRR contractual: solo cuentan los contratos que están cobrando o en prueba. */
export function contractualMrr(subscriptions: Subscription[]): number {
  return subscriptions
    .filter((s) => s.status === 'active' || s.status === 'trialing')
    .reduce((total, s) => total + s.monthly_amount, 0);
}

export function unpaidInvoices(invoices: Invoice[]): Invoice[] {
  return invoices.filter((i) => i.status === 'pending' || i.status === 'overdue');
}

/** Una instancia sana está lista Y sirviendo: `ready` sola no basta. */
export function healthyInstances(instances: Instance[]): Instance[] {
  return instances.filter((i) => i.provisioning_status === 'ready' && i.service_status === 'active');
}

/**
 * Borrar una cuenta solo se ofrece si no deja historia detrás. Con clientes,
 * contratos o facturas lo que corresponde es desactivarla.
 */
export function isAccountDeletable(clients: Client[], subscriptions: Subscription[], invoices: Invoice[]): boolean {
  return clients.length === 0 && subscriptions.length === 0 && invoices.length === 0;
}
