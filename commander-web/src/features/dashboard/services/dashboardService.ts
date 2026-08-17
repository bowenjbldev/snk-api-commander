import { useMemo } from 'react';
import { useApp } from '@app/provider';
import type { CommanderApiClient } from '@services/api/commanderApi';
import type {
  Account, Activity, Client, Instance, Invoice, Quota, Subscription, UsageBucket,
} from '@services/api/types';

export type DashboardData = [
  Account[], Client[], Instance[], Subscription[], Quota[], Invoice[], UsageBucket[], Activity[],
];

export interface DashboardService {
  /** Cada bloque solo se pide si el operador tiene permiso: sin él, lista vacía. */
  load(can: (perm: string) => boolean): Promise<DashboardData>;
}

export function createDashboardService(api: CommanderApiClient): DashboardService {
  return {
    load: (can) =>
      Promise.all([
        can('index accounts') ? api.list('accounts') : Promise.resolve([]),
        can('index clients') ? api.list('clients') : Promise.resolve([]),
        can('index instances') ? api.list('instances') : Promise.resolve([]),
        can('index subscriptions') ? api.list('subscriptions') : Promise.resolve([]),
        can('index quotas') ? api.list('quotas') : Promise.resolve([]),
        can('index invoices') ? api.list('invoices') : Promise.resolve([]),
        can('index instances') ? api.list('usage') : Promise.resolve([]),
        can('index activity') ? api.list('activity') : Promise.resolve([]),
      ]),
  };
}

export function useDashboardService(): DashboardService {
  const { api } = useApp();
  return useMemo(() => createDashboardService(api), [api]);
}
