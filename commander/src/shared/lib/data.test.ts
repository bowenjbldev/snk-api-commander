import assert from 'node:assert/strict';
import { test } from 'node:test';
import { groupCount, lut, rate } from './data.ts';

test('rate nunca devuelve infinito: dividir por cero es 0 %', () => {
  assert.equal(rate(3, 0), 0);
  assert.equal(rate(0, 0), 0);
  assert.equal(rate(25, 100), 25);
});

test('lut indexa por id y tolera null', () => {
  const map = lut([{ id: 4, name: 'cuatro' }]);
  assert.equal(map.get(4)?.name, 'cuatro');
  assert.equal(map.get(9), undefined);
  assert.equal(lut(null).size, 0);
});

test('groupCount cuenta por clave', () => {
  const counts = groupCount([{ s: 'ready' }, { s: 'ready' }, { s: 'failed' }], (row) => row.s);
  assert.deepEqual(counts, { ready: 2, failed: 1 });
});
