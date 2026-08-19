import assert from 'node:assert/strict';
import { test } from 'node:test';
import { statusTone } from './httpStatus.ts';

test('statusTone separa culpa del servidor y del cliente', () => {
  assert.equal(statusTone(500), 'error');
  assert.equal(statusTone(404), 'warn');
  assert.equal(statusTone(304), 'ok');
  assert.equal(statusTone(201), 'ok');
});
