import { useMemo } from 'react';
import { useApp } from '@app/provider';
import type { CommanderApiClient } from '@services/api/commanderApi';
import type { Account, Application, Client, Instance, Plan, Quota } from '@services/api/types';
import { toClientCreatePayload, toClientUpdatePayload } from '../model/clientForm';
import type { ClientFormValues } from '../model/clientForm';

export interface ClientsService {
  listWithRelations(): Promise<[Client[], Account[], Instance[], Application[]]>;
  get(id: string | number): Promise<Client>;
  getWithContext(
    id: string | number,
  ): Promise<[Client, Account[], Instance[], Application[], Plan[], Quota[]]>;
  listAccounts(): Promise<Account[]>;
  create(values: ClientFormValues): Promise<Client>;
  update(id: number, values: ClientFormValues): Promise<Client>;
}

export function createClientsService(api: CommanderApiClient): ClientsService {
  return {
    listWithRelations: () =>
      Promise.all([api.list('clients'), api.list('accounts'), api.list('instances'), api.list('applications')]),
    get: (id) => api.get('clients', id),
    getWithContext: (id) =>
      Promise.all([
        api.get('clients', id),
        api.list('accounts'),
        api.list('instances'),
        api.list('applications'),
        api.list('plans'),
        api.list('quotas'),
      ]),
    listAccounts: () => api.list('accounts'),
    create: (values) => api.createClient(toClientCreatePayload(values)),
    update: (id, values) => api.updateClient(id, toClientUpdatePayload(values)),
  };
}

export function useClientsService(): ClientsService {
  const { api } = useApp();
  return useMemo(() => createClientsService(api), [api]);
}
