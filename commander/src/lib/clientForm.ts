import type { Client, PaymentTerms } from '../store/types'
import { validEmail } from './input'

export interface ClientDraft {
  name: string
  company: string
  email: string
  phone: string
  notes: string
  active: boolean
  billingEmail: string
  taxId: string
  address1: string
  address2: string
  city: string
  postal: string
  country: string
  terms: PaymentTerms | ''
}

export const clientDraft = (client?: Client): ClientDraft => ({
  name: client?.name ?? '', company: client?.company ?? '', email: client?.email ?? '',
  phone: client?.phone ?? '', notes: client?.notes ?? '', active: client?.status !== 'inactive',
  billingEmail: client?.billing_email ?? '', taxId: client?.tax_id ?? '',
  address1: client?.address_line1 ?? '', address2: client?.address_line2 ?? '',
  city: client?.city ?? '', postal: client?.postal_code ?? '', country: client?.country ?? '',
  terms: client?.payment_terms ?? '',
})

export const clientErrors = (draft: ClientDraft) => ({
  name: draft.name.trim() ? '' : 'Required',
  email: !draft.email.trim() ? 'Required' : validEmail(draft.email) ? '' : 'Enter a valid email',
  billingEmail: !draft.billingEmail.trim() || validEmail(draft.billingEmail) ? '' : 'Enter a valid email',
  country: !draft.country.trim() || draft.country.trim().length === 2 ? '' : 'Use a 2-letter code',
})

export const clientPayload = (d: ClientDraft): Omit<Client, 'id' | 'created_at' | 'slug'> => ({
  name: d.name.trim(), company: d.company.trim(), email: d.email.trim(),
  phone: d.phone.trim() || undefined, notes: d.notes.trim() || undefined,
  status: d.active ? 'active' : 'inactive', billing_email: d.billingEmail.trim() || null,
  tax_id: d.taxId.trim() || null, address_line1: d.address1.trim() || null,
  address_line2: d.address2.trim() || null, city: d.city.trim() || null,
  postal_code: d.postal.trim() || null, country: d.country.trim().toUpperCase() || null,
  payment_terms: d.terms || null,
})
