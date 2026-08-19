import assert from 'node:assert/strict';
import { test } from 'node:test';
import { emptyClientValues, toClientCreatePayload, toClientUpdatePayload } from './clientForm.ts';

const values = { ...emptyClientValues(), account_id: '3', slug: ' faro-cadiz ', name: ' Faro ', email: ' a@b.es ', status: 'active' };

test('el alta lleva cuenta y slug', () => {
  const payload = toClientCreatePayload(values);
  assert.equal(payload.account_id, 3);
  assert.equal(payload.slug, 'faro-cadiz');
  assert.equal(payload.name, 'Faro');
});

test('la edición NO lleva cuenta ni slug: son inmutables tras el alta', () => {
  const payload = toClientUpdatePayload(values);
  assert.equal('account_id' in payload, false);
  assert.equal('slug' in payload, false);
  assert.equal(payload.email, 'a@b.es');
});

test('el alta desde una cuenta llega con ella elegida', () => {
  assert.equal(emptyClientValues(null, '7').account_id, '7');
});

test('un estado desconocido no llega al servidor', () => {
  assert.equal(toClientUpdatePayload({ ...values, status: 'zombi' }).status, 'active');
});
