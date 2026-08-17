import type { Invoice, InvoiceStatus, InvoiceLine } from '@services/api/types';
import { asInvoiceStatus } from '@shared/lib/enums';

/** Línea en edición: el contrato puede estar sin elegir todavía. */
export type DraftLine = Pick<InvoiceLine, 'subscription_id' | 'item_id' | 'description' | 'quantity' | 'unit_price'>;

export const EMPTY_LINE: DraftLine = {
  subscription_id: 0,
  item_id: null,
  description: '',
  quantity: 1,
  unit_price: 0,
};

export type InvoiceFormValues = {
  number: string;
  currency: string;
  issued_at: string;
  due_at: string;
  status: string;
};

export function emptyInvoiceValues(): InvoiceFormValues {
  return { number: '', currency: 'EUR', issued_at: '', due_at: '', status: 'draft' };
}

/**
 * Subtotal de ayuda en el navegador. El total que manda es el que devuelve el
 * servidor: aquí solo se muestra para que el operador no emita a ciegas.
 */
export function draftSubtotal(lines: DraftLine[]): number {
  return lines.reduce((total, l) => total + Number(l.quantity || 0) * Number(l.unit_price || 0), 0);
}

export function toInvoicePayload(
  values: InvoiceFormValues,
  accountId: number,
  lines: DraftLine[],
): Partial<Invoice> {
  return {
    account_id: accountId,
    number: values.number.trim(),
    currency: values.currency.trim() || 'EUR',
    issued_at: values.issued_at,
    due_at: values.due_at,
    status: asInvoiceStatus(values.status) as InvoiceStatus,
    lines: lines.map((l) => ({
      subscription_id: Number(l.subscription_id),
      item_id: l.item_id === null ? null : Number(l.item_id),
      instance_id: null,
      description: l.description.trim(),
      quantity: Number(l.quantity),
      unit_price: Number(l.unit_price),
      amount: Number(l.quantity) * Number(l.unit_price),
    })),
  };
}

/** Tras emitir solo cambian estado y fechas: el resto es historia contable. */
export function toInvoiceUpdatePayload(status: string, issuedAt: string, dueAt: string): Partial<Invoice> {
  return { status: asInvoiceStatus(status) as InvoiceStatus, issued_at: issuedAt, due_at: dueAt };
}
