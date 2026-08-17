import { useMemo } from 'react';
import { useApp } from '@app/provider';
import type { CommanderApiClient } from '@services/api/commanderApi';
import type { Account, Activity, Application, Client, Instance, Invoice, Subscription } from '@services/api/types';
import { toAccountPayload } from '../model/accountForm';
import type { AccountFormValues } from '../model/accountForm';

export interface AccountsService {
  listWithRelations(): Promise<[Account[], Client[], Subscription[], Invoice[]]>;
  get(id: string | number): Promise<Account>;
  getWithContext(
    id: string | number,
  ): Promise<[Account, Client[], Subscription[], Invoice[], Instance[], Application[], Activity[]]>;
  create(values: AccountFormValues): Promise<Account>;
  update(id: number, values: AccountFormValues): Promise<Account>;
}

export function createAccountsService(api: CommanderApiClient): AccountsService {
  return {
    listWithRelations: () =>
      Promise.all([api.list('accounts'), api.list('clients'), api.list('subscriptions'), api.list('invoices')]),
    get: (id) => api.get('accounts', id),
    getWithContext: (id) =>
      Promise.all([
        api.get('accounts', id),
        api.list('clients'),
        api.list('subscriptions'),
        api.list('invoices'),
        api.list('instances'),
        api.list('applications'),
        api.list('activity'),
      ]),
    create: (values) => api.createAccount(toAccountPayload(values)),
    update: (id, values) => api.updateAccount(id, toAccountPayload(values)),
  };
}

export function useAccountsService(): AccountsService {
  const { api } = useApp();
  return useMemo(() => createAccountsService(api), [api]);
}
