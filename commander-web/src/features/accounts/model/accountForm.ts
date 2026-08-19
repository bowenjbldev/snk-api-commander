import { ENTITY_STATUS } from '@services/api/mock/commander-mock';
import type { Account } from '@services/api/types';
import { asEntityStatus } from '@shared/lib/enums';

export type AccountFormValues = {
  name: string;
  status: string;
  email: string;
  phone: string;
  billing_email: string;
  tax_id: string;
  payment_terms: string;
  address: string;
  country: string;
  notes: string;
};

export const PAYMENT_TERMS = ['15 días', '30 días', '45 días', '60 días', 'Al contado'];

/**
 * El catálogo de estados tiene tres valores, pero un pagador solo se da de alta o se
 * retira: «suspendido» es una decisión de operación sobre la instancia, no sobre la cuenta.
 */
export const ACCOUNT_STATUS_OPTIONS = (['active', 'inactive'] as const).map((value) => ({
  value,
  label: ENTITY_STATUS[value].label,
}));

/** Un único país mientras la facturación no salga de España. */
export const COUNTRY_OPTIONS = [{ value: 'ES', label: 'España' }];

export function emptyAccountValues(account?: Account | null): AccountFormValues {
  return {
    name: account?.name ?? '',
    status: account?.status ?? 'active',
    email: account?.email ?? '',
    phone: account?.phone ?? '',
    billing_email: account?.billing_email ?? '',
    tax_id: account?.tax_id ?? '',
    payment_terms: account?.payment_terms ?? '30 días',
    address: account?.address ?? '',
    country: account?.country ?? 'ES',
    notes: account?.notes ?? '',
  };
}

/** El formulario habla en `string`; la API espera uniones. Aquí se estrecha. */
export function toAccountPayload(values: AccountFormValues): Partial<Account> {
  return {
    name: values.name.trim(),
    status: asEntityStatus(values.status),
    email: values.email.trim(),
    phone: values.phone.trim(),
    billing_email: values.billing_email.trim(),
    tax_id: values.tax_id.trim().toUpperCase(),
    payment_terms: values.payment_terms,
    address: values.address.trim(),
    country: values.country.trim(),
    notes: values.notes,
  };
}
