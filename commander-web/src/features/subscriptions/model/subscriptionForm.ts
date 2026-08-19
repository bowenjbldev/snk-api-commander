import type { Subscription } from '@services/api/types';
import { asSubscriptionStatus } from '@shared/lib/enums';

export type SubscriptionFormValues = {
  account_id: string;
  application_id: string;
  name: string;
  mode: string;
  status: string;
  monthly_amount: number | string;
  max_instances: number | string;
  starts_at: string;
};

/** El alta desde la ficha de una cuenta llega con ella en la URL: se respeta. */
export function emptySubscriptionValues(accountId?: string): SubscriptionFormValues {
  return {
    account_id: accountId ?? '',
    application_id: '',
    name: '',
    mode: 'negotiated',
    status: 'active',
    monthly_amount: '',
    max_instances: 1,
    starts_at: new Date().toISOString().slice(0, 10),
  };
}

export function toSubscriptionPayload(values: SubscriptionFormValues): Partial<Subscription> {
  return {
    account_id: Number(values.account_id),
    application_id: Number(values.application_id),
    name: values.name.trim(),
    mode: values.mode === 'catalog' ? 'catalog' : 'negotiated',
    status: asSubscriptionStatus(values.status),
    // Un importe vacío no es cero: convertirlo silenciaría el 422 del servidor y
    // dejaría pactado un contrato de 0 € que nadie ha escrito.
    monthly_amount: values.monthly_amount === '' ? undefined : Number(values.monthly_amount),
    max_instances: values.max_instances === '' ? undefined : Number(values.max_instances),
    starts_at: values.starts_at,
  };
}
