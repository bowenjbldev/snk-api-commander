import { useMemo } from 'react';
import { useApp } from '@app/provider';
import type { CommanderApiClient } from '@services/api/commanderApi';
import type {
  Account, Activity, Application, Client, Instance, Invoice, Plan, Quota, Subscription, SubscriptionItem,
} from '@services/api/types';
import { toSubscriptionPayload } from '../model/subscriptionForm';
import type { SubscriptionFormValues } from '../model/subscriptionForm';

export interface SubscriptionsService {
  listWithRelations(): Promise<[Subscription[], Account[], Application[], SubscriptionItem[]]>;
  getWithContext(
    id: string | number,
  ): Promise<[Subscription, Account[], Application[], SubscriptionItem[], Client[], Instance[], Plan[], Quota[], Invoice[], Activity[]]>;
  listCatalog(): Promise<[Account[], Application[]]>;
  create(values: SubscriptionFormValues): Promise<Subscription>;
  updateTerms(id: number, patch: Partial<Subscription>): Promise<Subscription>;
  createSharedQuota(subscriptionId: number, data: Partial<Quota>): Promise<Quota>;
}

export function createSubscriptionsService(api: CommanderApiClient): SubscriptionsService {
  return {
    listWithRelations: () =>
      Promise.all([api.list('subscriptions'), api.list('accounts'), api.list('applications'), api.list('items')]),
    getWithContext: (id) =>
      Promise.all([
        api.get('subscriptions', id),
        api.list('accounts'),
        api.list('applications'),
        api.list('items'),
        api.list('clients'),
        api.list('instances'),
        api.list('plans'),
        api.list('quotas'),
        api.list('invoices'),
        api.list('activity'),
      ]),
    listCatalog: () => Promise.all([api.list('accounts'), api.list('applications')]),
    create: (values) => api.createSubscription(toSubscriptionPayload(values)),
    updateTerms: (id, patch) => api.updateSubscription(id, patch),
    createSharedQuota: (subscriptionId, data) =>
      api.createSharedQuota({ ...data, subscription_id: subscriptionId } as Partial<Quota> & { subscription_id: number }),
  };
}

export function useSubscriptionsService(): SubscriptionsService {
  const { api } = useApp();
  return useMemo(() => createSubscriptionsService(api), [api]);
}
