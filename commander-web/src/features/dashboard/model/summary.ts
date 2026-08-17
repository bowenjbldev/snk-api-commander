import type { Account, Client, Instance, Invoice, Quota, Subscription } from '@services/api/types';
import { contractualMrr } from '@features/accounts/model/accountTotals';
import { hasIncident } from '@features/instances/model/instanceActions';
import { isExceeded, quotaPercent } from '@features/quotas/model/quotaHealth';

export interface DashboardSummary {
  activeAccounts: number;
  totalAccounts: number;
  activeClients: number;
  totalClients: number;
  readyInstances: Instance[];
  incidentInstances: Instance[];
  totalInstances: number;
  mrr: number;
  requests24h: number;
  exceededQuotas: Quota[];
  overdueInvoices: Invoice[];
  overdueTotal: number;
  topQuotas: Quota[];
}

/**
 * El dashboard no tiene endpoint propio: se deriva de los mismos listados que
 * alimentan las páginas. Por eso es un cálculo puro y no una llamada más.
 */
export function buildSummary(
  accounts: Account[],
  clients: Client[],
  instances: Instance[],
  subscriptions: Subscription[],
  quotas: Quota[],
  invoices: Invoice[],
): DashboardSummary {
  const overdue = invoices.filter((i) => i.status === 'overdue');
  return {
    activeAccounts: accounts.filter((a) => a.status === 'active').length,
    totalAccounts: accounts.length,
    activeClients: clients.filter((c) => c.status === 'active').length,
    totalClients: clients.length,
    readyInstances: instances.filter((i) => i.provisioning_status === 'ready'),
    incidentInstances: instances.filter(hasIncident),
    totalInstances: instances.length,
    mrr: contractualMrr(subscriptions),
    requests24h: instances.reduce((total, i) => total + i.requests_24h, 0),
    exceededQuotas: quotas.filter(isExceeded),
    overdueInvoices: overdue,
    overdueTotal: overdue.reduce((t, i) => t + i.total, 0),
    topQuotas: [...quotas]
      .filter((q) => q.limit_value && q.current_value !== null)
      .sort((a, b) => (quotaPercent(b) ?? 0) - (quotaPercent(a) ?? 0))
      .slice(0, 6),
  };
}
