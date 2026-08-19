import type { Client } from '@services/api/types';
import { asEntityStatus } from '@shared/lib/enums';

export type ClientFormValues = {
  account_id: string;
  slug: string;
  name: string;
  email: string;
  status: string;
  notes: string;
};

/** En el alta no se preselecciona ninguna cuenta salvo que la traiga la URL. */
export function emptyClientValues(client?: Client | null, accountId?: string | null): ClientFormValues {
  return {
    account_id: String(client?.account_id ?? accountId ?? ''),
    slug: client?.slug ?? '',
    name: client?.name ?? '',
    email: client?.email ?? '',
    status: client?.status ?? 'active',
    notes: client?.notes ?? '',
  };
}

/**
 * En el alta viajan cuenta y slug; en la edición no. Son inmutables porque el slug
 * ya es un subdominio en uso y la cuenta ya factura.
 */
export function toClientCreatePayload(values: ClientFormValues): Partial<Client> {
  return {
    account_id: Number(values.account_id),
    slug: values.slug.trim(),
    ...toClientUpdatePayload(values),
  };
}

export function toClientUpdatePayload(values: ClientFormValues): Partial<Client> {
  return {
    name: values.name.trim(),
    email: values.email.trim(),
    status: asEntityStatus(values.status),
    notes: values.notes,
  };
}
