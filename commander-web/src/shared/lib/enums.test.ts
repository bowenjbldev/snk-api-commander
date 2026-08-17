import assert from 'node:assert/strict';
import { test } from 'node:test';
import { asEntityStatus, asInvoiceStatus, asQuotaAction, asSubscriptionStatus } from './enums.ts';

test('un valor conocido pasa tal cual', () => {
  assert.equal(asEntityStatus('inactive'), 'inactive');
  assert.equal(asSubscriptionStatus('past_due'), 'past_due');
  assert.equal(asInvoiceStatus('overdue'), 'overdue');
  assert.equal(asQuotaAction('suspend'), 'suspend');
});

test('un valor desconocido no viaja al servidor: cae al defecto', () => {
  assert.equal(asEntityStatus('borrado'), 'active');
  assert.equal(asSubscriptionStatus(''), 'active');
  assert.equal(asInvoiceStatus('cobrada'), 'draft');
  assert.equal(asQuotaAction('explotar'), 'warn');
});
