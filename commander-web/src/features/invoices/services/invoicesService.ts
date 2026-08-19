import { useMemo } from 'react';
import { useApp } from '@app/provider';
import type { CommanderApiClient } from '@services/api/commanderApi';
import type { Account, Client, Invoice, Subscription, SubscriptionItem } from '@services/api/types';
import { toInvoicePayload, toInvoiceUpdatePayload } from '../model/invoiceLines';
import type { DraftLine, InvoiceFormValues } from '../model/invoiceLines';

export interface InvoicesService {
  listWithAccounts(): Promise<[Invoice[], Account[]]>;
  getWithContext(id: string | number): Promise<[Invoice, Account[], Subscription[]]>;
  listFormCatalog(): Promise<[Account[], Subscription[], SubscriptionItem[], Client[]]>;
  create(values: InvoiceFormValues, lines: DraftLine[]): Promise<Invoice>;
  updateStatus(id: number, status: string, issuedAt: string, dueAt: string): Promise<Invoice>;
}

export function createInvoicesService(api: CommanderApiClient): InvoicesService {
  return {
    listWithAccounts: () => Promise.all([api.list('invoices'), api.list('accounts')]),
    getWithContext: (id) => Promise.all([api.get('invoices', id), api.list('accounts'), api.list('subscriptions')]),
    listFormCatalog: () =>
      Promise.all([api.list('accounts'), api.list('subscriptions'), api.list('items'), api.list('clients')]),
    create: (values, lines) => api.createInvoice(toInvoicePayload(values, lines)),
    updateStatus: (id, status, issuedAt, dueAt) => api.updateInvoice(id, toInvoiceUpdatePayload(status, issuedAt, dueAt)),
  };
}

export function useInvoicesService(): InvoicesService {
  const { api } = useApp();
  return useMemo(() => createInvoicesService(api), [api]);
}
