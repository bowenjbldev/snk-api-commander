import assert from 'node:assert/strict';
import { test } from 'node:test';
import { statusClass, statusTone } from './httpStatus.ts';

test('statusClass agrupa por centena', () => {
  assert.equal(statusClass(200), '2xx');
  assert.equal(statusClass(304), '3xx');
  assert.equal(statusClass(422), '4xx');
  assert.equal(statusClass(503), '5xx');
});

test('statusTone separa culpa del servidor y del cliente', () => {
  assert.equal(statusTone(500), 'error');
  assert.equal(statusTone(404), 'warn');
  assert.equal(statusTone(201), undefined);
});
