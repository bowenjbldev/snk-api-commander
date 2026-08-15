import assert from 'node:assert/strict';
import { test } from 'node:test';
import { emptyOperatorValues, toOperatorPayload } from './operatorForm.ts';

test('una contraseña vacía al editar no viaja en el payload', () => {
  const values = { ...emptyOperatorValues(), name: 'Marta', email: 'm@commander.es' };
  const payload = toOperatorPayload(values, 7);

  assert.equal(payload.id, 7);
  assert.equal('password' in payload, false, 'vacío significa «no la cambies», no «bórrala»');
});

test('una contraseña escrita sí viaja', () => {
  const values = { ...emptyOperatorValues(), name: 'Marta', email: 'm@commander.es', password: 'doceletras12' };
  assert.equal(toOperatorPayload(values).password, 'doceletras12');
});

test('los campos se recortan antes de enviarse', () => {
  const values = { ...emptyOperatorValues(), name: '  Marta  ', email: ' m@commander.es ' };
  const payload = toOperatorPayload(values);

  assert.equal(payload.name, 'Marta');
  assert.equal(payload.email, 'm@commander.es');
});

test('un alta sin id no inventa uno', () => {
  assert.equal(toOperatorPayload(emptyOperatorValues()).id, undefined);
});
