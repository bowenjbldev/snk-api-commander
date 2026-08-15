import type { Subscription, SubscriptionMode, SubscriptionStatus } from '@services/api/types';
import { asSubscriptionStatus } from '@shared/lib/enums';

export type SubscriptionFormValues = {
  account_id: string;
  application_id: string;
  name: string;
  mode: string;
  status: string;
  monthly_amount: number | string;
  currency: string;
  max_instances: number | string;
  starts_at: string;
  notes: string;
};

export function emptySubscriptionValues(accountId?: number, applicationId?: number): SubscriptionFormValues {
  return {
    account_id: String(accountId ?? ''),
    application_id: String(applicationId ?? ''),
    name: '',
    mode: 'negotiated',
    status: 'active',
    monthly_amount: 0,
    currency: 'EUR',
    max_instances: 1,
    starts_at: '',
    notes: '',
  };
}

export function toSubscriptionPayload(values: SubscriptionFormValues): Partial<Subscription> {
  return {
    account_id: Number(values.account_id),
    application_id: Number(values.application_id),
    name: values.name.trim(),
    mode: (values.mode === 'catalog' ? 'catalog' : 'negotiated') as SubscriptionMode,
    status: asSubscriptionStatus(values.status) as SubscriptionStatus,
    monthly_amount: Number(values.monthly_amount),
    currency: values.currency.trim() || 'EUR',
    max_instances: Number(values.max_instances),
    starts_at: values.starts_at,
    notes: values.notes,
  };
}
