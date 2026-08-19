import assert from 'node:assert/strict';
import { test } from 'node:test';
import { effectiveAction, hasNoLimit, normalizeLimits, summarizeLimits } from './planLimits.ts';
import type { PlanLimitDraft } from './planLimits.ts';
import { priceBand } from './priceBands.ts';

const row = (over: Partial<PlanLimitDraft> = {}): PlanLimitDraft => ({
  metric_key: 'requests_day',
  limit_value: 1000,
  warning_percent: 80,
  action: 'warn',
  consecutive_periods: 2,
  ...over,
});

test('un límite vacío significa medir sin limitar', () => {
  assert.equal(hasNoLimit(row({ limit_value: null })), true);
  assert.equal(hasNoLimit(row({ limit_value: '' })), true);
  assert.equal(hasNoLimit(row()), false);
});

test('sin límite la acción cae a observación aunque el borrador diga otra cosa', () => {
  assert.equal(effectiveAction(row({ limit_value: null, action: 'suspend' })), 'none');
  assert.equal(effectiveAction(row({ action: 'suspend' })), 'suspend');
});

test('normalizeLimits manda null, nunca cero, cuando no hay límite', () => {
  const [sinLimite] = normalizeLimits([row({ limit_value: null, action: 'suspend' })]);
  assert.equal(sinLimite.limit_value, null);
  assert.equal(sinLimite.warning_percent, null);
  assert.equal(sinLimite.action, 'none');
  assert.equal(sinLimite.consecutive_periods, null);
});

test('normalizeLimits convierte los textos del formulario a números', () => {
  const [limitado] = normalizeLimits([row({ limit_value: '2048', warning_percent: '90' })]);
  assert.equal(limitado.limit_value, 2048);
  assert.equal(limitado.warning_percent, 90);
});

// Cifras de tres dígitos a propósito: el separador de millares depende del ICU del
// runtime y aquí se comprueba el resumen, no el formateo.
test('summarizeLimits enseña dos métricas y cuenta el resto', () => {
  const solo = summarizeLimits(normalizeLimits([row({ limit_value: null })]));
  assert.equal(solo, 'Todas las métricas sin límite');

  const dos = summarizeLimits(
    normalizeLimits([row({ limit_value: 500 }), row({ metric_key: 'reads_month', limit_value: 900 })]),
  );
  assert.equal(dos, 'Requests del día 500 · Lecturas del mes 900');

  const tres = summarizeLimits(
    normalizeLimits([
      row({ limit_value: 500 }),
      row({ metric_key: 'reads_month', limit_value: 900 }),
      row({ metric_key: 'users_total', limit_value: 10 }),
    ]),
  );
  assert.equal(tres, 'Requests del día 500 · Lecturas del mes 900 · +1 más');
});

test('los cortes del filtro de precio dejan 200 y 700 dentro del tramo medio', () => {
  assert.equal(priceBand(199.99), 'low');
  assert.equal(priceBand(200), 'mid');
  assert.equal(priceBand(700), 'mid');
  assert.equal(priceBand(700.01), 'high');
});
