import assert from 'node:assert/strict';
import { test } from 'node:test';
import { EMPTY_LINE, draftSubtotal, emptyInvoiceValues, toInvoicePayload, toInvoiceUpdatePayload } from './invoiceLines.ts';

test('el subtotal multiplica cantidad por precio en cada línea', () => {
  assert.equal(
    draftSubtotal([
      { ...EMPTY_LINE, subscription_id: '1', quantity: '2', unit_price: '100' },
      { ...EMPTY_LINE, subscription_id: '2', quantity: '1', unit_price: '50' },
    ]),
    250,
  );
});

test('una línea a medio escribir no rompe el subtotal', () => {
  assert.equal(draftSubtotal([{ ...EMPTY_LINE, quantity: '', unit_price: '10' }]), 0);
  assert.equal(draftSubtotal([{ ...EMPTY_LINE, quantity: 'dos', unit_price: '10' }]), 0);
});

test('el payload calcula el importe de cada línea y deja la cabecera vacía en nulo', () => {
  const payload = toInvoicePayload(
    { ...emptyInvoiceValues('7'), number: 'F-1 ' },
    [{ ...EMPTY_LINE, subscription_id: '3', description: ' mensualidad ', quantity: '2', unit_price: '90' }],
  );
  assert.equal(payload.account_id, 7);
  assert.equal(payload.number, 'F-1');
  assert.equal(payload.lines?.[0].amount, 180);
  assert.equal(payload.lines?.[0].item_id, null);
  assert.equal(payload.lines?.[0].description, 'mensualidad');
});

test('la actualización solo lleva estado y fechas', () => {
  const payload = toInvoiceUpdatePayload('paid', '2026-01-01', '2026-02-01');
  assert.deepEqual(Object.keys(payload).sort(), ['due_at', 'issued_at', 'status']);
});

test('un estado inventado cae a borrador', () => {
  assert.equal(toInvoiceUpdatePayload('cobrada', '', '').status, 'draft');
});
