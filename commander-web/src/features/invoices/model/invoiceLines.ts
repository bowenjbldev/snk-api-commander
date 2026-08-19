import { INVOICE_STATUS } from '@services/api/mock/commander-mock';
import type { Invoice } from '@services/api/types';
import { asInvoiceStatus } from '@shared/lib/enums';

/** Los cinco estados del catálogo: los mismos en el alta, en la fila y en la ficha. */
export const INVOICE_STATUS_OPTIONS = Object.entries(INVOICE_STATUS).map(([value, meta]) => ({
  value,
  label: meta.label,
}));

/**
 * Línea en edición. Todos los campos son cadenas porque un input vacío no es cero: un
 * precio a medio teclear no puede convertirse en un 0 que el operador no ha escrito.
 */
export interface DraftLine {
  subscription_id: string;
  item_id: string;
  description: string;
  quantity: string;
  unit_price: string;
}

export const EMPTY_LINE: DraftLine = {
  subscription_id: '',
  item_id: '',
  description: '',
  quantity: '1',
  unit_price: '',
};

export interface InvoiceFormValues {
  account_id: string;
  number: string;
  currency: string;
  status: string;
  issued_at: string;
  due_at: string;
}

const today = () => new Date().toISOString().slice(0, 10);

/**
 * Propuestas de partida, no reglas: el número sugerido y el vencimiento a treinta días
 * se editan, y el servidor sigue siendo quien rechaza un número ya emitido.
 */
export function emptyInvoiceValues(accountId = ''): InvoiceFormValues {
  return {
    account_id: accountId,
    number: `F-2026-${9000 + Math.floor(Math.random() * 900)}`,
    currency: 'EUR',
    status: 'draft',
    issued_at: today(),
    due_at: new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10),
  };
}

/**
 * Subtotal de ayuda en el navegador. El total que manda es el que devuelve el
 * servidor: aquí solo se muestra para que el operador no emita a ciegas.
 */
export function draftSubtotal(lines: DraftLine[]): number {
  return lines.reduce((total, l) => total + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0);
}

export function toInvoicePayload(values: InvoiceFormValues, lines: DraftLine[]): Partial<Invoice> {
  return {
    account_id: Number(values.account_id),
    number: values.number.trim(),
    currency: values.currency,
    status: asInvoiceStatus(values.status),
    issued_at: values.issued_at,
    due_at: values.due_at,
    lines: lines.map((l) => ({
      subscription_id: Number(l.subscription_id),
      item_id: l.item_id === '' ? null : Number(l.item_id),
      // La cabecera elegida no dice qué instancia se factura: esa columna la rellena
      // el servidor, y el alta desde el navegador nunca la inventa.
      instance_id: null,
      description: l.description.trim(),
      quantity: Number(l.quantity),
      unit_price: Number(l.unit_price),
      amount: (Number(l.quantity) || 0) * (Number(l.unit_price) || 0),
    })),
  };
}

/** Tras emitir solo cambian estado y fechas: el resto es historia contable. */
export function toInvoiceUpdatePayload(status: string, issuedAt: string, dueAt: string): Partial<Invoice> {
  return { status: asInvoiceStatus(status), issued_at: issuedAt, due_at: dueAt };
}
