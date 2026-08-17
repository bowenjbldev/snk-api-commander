import assert from 'node:assert/strict';
import { test } from 'node:test';
import { balancedColumns } from './grid.ts';

test('los ocho indicadores del resumen caben en 4×2 sin huecos', () => {
  assert.equal(balancedColumns(8), 4);
});

test('un reparto exacto gana al que deja huecos', () => {
  assert.equal(balancedColumns(6), 3); // 3+3, no 4+2
  assert.equal(balancedColumns(9), 3); // 3+3+3, no 4+4+1
});

test('sin reparto exacto elige el que deja menos huecos', () => {
  assert.equal(balancedColumns(5), 3); // 3+2 deja un hueco; 4+1 dejaría tres
  assert.equal(balancedColumns(7), 4); // 4+3 deja uno, como 2 columnas, pero en menos filas
});

test('pocas celdas ocupan una sola fila', () => {
  assert.equal(balancedColumns(1), 1);
  assert.equal(balancedColumns(3), 3);
  assert.equal(balancedColumns(4), 4);
});

test('nunca devuelve cero columnas aunque no haya celdas', () => {
  assert.equal(balancedColumns(0), 1);
});
