import { useMemo } from 'react';
import { useApp } from '@app/provider';
import type { CommanderApiClient } from '@services/api/commanderApi';
import type { Account, Application, Client, Instance, Measurement, Quota, Subscription } from '@services/api/types';

export interface QuotasService {
  listWithContext(): Promise<[Quota[], Instance[], Client[], Account[], Application[], Subscription[]]>;
  getWithSeries(
    id: string | number,
  ): Promise<[Quota, Measurement[], Instance[], Client[], Subscription[]]>;
  update(id: number, patch: Partial<Quota>): Promise<Quota>;
}

export function createQuotasService(api: CommanderApiClient): QuotasService {
  return {
    listWithContext: () =>
      Promise.all([
        api.list('quotas'),
        api.list('instances'),
        api.list('clients'),
        api.list('accounts'),
        api.list('applications'),
        api.list('subscriptions'),
      ]),
    getWithSeries: (id) =>
      Promise.all([
        api.get('quotas', id),
        api.measurements(id),
        api.list('instances'),
        api.list('clients'),
        api.list('subscriptions'),
      ]),
    update: (id, patch) => api.updateQuota(id, patch),
  };
}

export function useQuotasService(): QuotasService {
  const { api } = useApp();
  return useMemo(() => createQuotasService(api), [api]);
}
