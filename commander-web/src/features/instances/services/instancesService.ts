import { useMemo } from 'react';
import { useApp } from '@app/provider';
import type { CommanderApiClient } from '@services/api/commanderApi';
import type {
  Account, Activity, Application, Client, Instance, LogEntry, Plan, Quota, Subscription, UsageBucket,
} from '@services/api/types';
import type { InstanceAction } from '../model/instanceActions';

export interface InstancesService {
  listWithContext(): Promise<[Instance[], Client[], Account[], Application[], Plan[], Quota[]]>;
  getWithContext(
    id: string | number,
  ): Promise<[Instance, Client[], Account[], Application[], Plan[], Subscription[], Quota[], Activity[]]>;
  usage(id: string | number): Promise<UsageBucket[]>;
  logs(id: string | number): Promise<LogEntry[]>;
  run(id: string | number, action: InstanceAction, data: Record<string, unknown>): Promise<Instance>;
  /** Las cuotas de la ficha se editan sin salir de ella: es el mismo PUT /quotas/:id. */
  saveQuota(id: number, patch: Partial<Quota>): Promise<Quota>;
}

export function createInstancesService(api: CommanderApiClient): InstancesService {
  return {
    listWithContext: () =>
      Promise.all([
        api.list('instances'),
        api.list('clients'),
        api.list('accounts'),
        api.list('applications'),
        api.list('plans'),
        api.list('quotas'),
      ]),
    getWithContext: (id) =>
      Promise.all([
        api.get('instances', id),
        api.list('clients'),
        api.list('accounts'),
        api.list('applications'),
        api.list('plans'),
        api.list('subscriptions'),
        api.list('quotas'),
        api.list('activity'),
      ]),
    usage: (id) => api.instanceUsage(id),
    logs: (id) => api.instanceLogs(id),
    run: (id, action, data) => api.instanceAction(id, action, data),
    saveQuota: (id, patch) => api.updateQuota(id, patch),
  };
}

export function useInstancesService(): InstancesService {
  const { api } = useApp();
  return useMemo(() => createInstancesService(api), [api]);
}
