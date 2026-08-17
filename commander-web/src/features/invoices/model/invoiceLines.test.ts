import assert from 'node:assert/strict';
import { test } from 'node:test';
import { draftSubtotal, emptyInvoiceValues, toInvoicePayload, toInvoiceUpdatePayload } from './invoiceLines.ts';

test('el subtotal multiplica cantidad por precio en cada línea', () => {
  assert.equal(
    draftSubtotal([
      { subscription_id: 1, item_id: null, description: 'a', quantity: 2, unit_price: 100 },
      { subscription_id: 2, item_id: null, description: 'b', quantity: 1, unit_price: 50 },
    ]),
    250,
  );
});

test('una línea a medio escribir no rompe el subtotal', () => {
  assert.equal(
    draftSubtotal([{ subscription_id: 0, item_id: null, description: '', quantity: '' as unknown as number, unit_price: 10 }]),
    0,
  );
});

test('el payload calcula el importe de cada línea', () => {
  const payload = toInvoicePayload({ ...emptyInvoiceValues(), number: 'F-1' }, 7, [
    { subscription_id: 3, item_id: null, description: ' mensualidad ', quantity: 2, unit_price: 90 },
  ]);
  assert.equal(payload.account_id, 7);
  assert.equal(payload.lines?.[0].amount, 180);
  assert.equal(payload.lines?.[0].description, 'mensualidad');
});

test('la actualización solo lleva estado y fechas', () => {
  const payload = toInvoiceUpdatePayload('paid', '2026-01-01', '2026-02-01');
  assert.deepEqual(Object.keys(payload).sort(), ['due_at', 'issued_at', 'status']);
});

test('un estado inventado cae a borrador', () => {
  assert.equal(toInvoiceUpdatePayload('cobrada', '', '').status, 'draft');
});
