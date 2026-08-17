import assert from 'node:assert/strict';
import { test } from 'node:test';
import { effectiveAction, hasNoLimit, normalizeLimits, summarizeLimits } from './planLimits.ts';
import type { PlanLimitDraft } from './planLimits.ts';

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
  const [limitado] = normalizeLimits([row({ limit_value: '2048' as unknown as number, warning_percent: '90' as unknown as number })]);
  assert.equal(limitado.limit_value, 2048);
  assert.equal(limitado.warning_percent, 90);
});

test('summarizeLimits separa las que limitan de las que solo miden', () => {
  const resumen = summarizeLimits(normalizeLimits([row(), row({ metric_key: 'reads_month', limit_value: null })]));
  assert.equal(resumen, '1 con límite · 1 solo medición');
});
