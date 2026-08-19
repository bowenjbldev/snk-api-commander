import { useMemo } from 'react';
import { useApp } from '@app/provider';
import type { CommanderApiClient } from '@services/api/commanderApi';
import type {
  Account,
  Activity,
  Application,
  Client,
  Instance,
  Invoice,
  Subscription,
  SubscriptionItem,
} from '@services/api/types';
import { toAccountPayload } from '../model/accountForm';
import type { AccountFormValues } from '../model/accountForm';

export interface AccountsService {
  listWithRelations(): Promise<[Account[], Client[], Subscription[]]>;
  get(id: string | number): Promise<Account>;
  getWithContext(
    id: string | number,
  ): Promise<
    [Account, Client[], Subscription[], SubscriptionItem[], Invoice[], Instance[], Application[], Activity[]]
  >;
  create(values: AccountFormValues): Promise<Account>;
  update(id: number, values: AccountFormValues): Promise<Account>;
}

export function createAccountsService(api: CommanderApiClient): AccountsService {
  return {
    listWithRelations: () => Promise.all([api.list('accounts'), api.list('clients'), api.list('subscriptions')]),
    get: (id) => api.get('accounts', id),
    // Las cabeceras (`items`) entran por la capacidad de cada contrato: sin ellas la
    // columna solo podría enseñar el máximo pactado, que es la mitad del dato.
    getWithContext: (id) =>
      Promise.all([
        api.get('accounts', id),
        api.list('clients'),
        api.list('subscriptions'),
        api.list('items'),
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
