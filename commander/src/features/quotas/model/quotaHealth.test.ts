import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isExceeded, quotaHealth, quotaPercent, worstQuotaByInstance } from './quotaHealth.ts';
import type { Quota } from '@services/api/types';

const quota = (over: Partial<Quota> = {}): Quota => ({
  id: 1,
  scope: 'instance',
  instance_id: 10,
  subscription_id: null,
  account_id: 1,
  application_id: 1,
  metric_key: 'requests_day',
  limit_value: 100,
  current_value: 50,
  warning_percent: 80,
  action: 'warn',
  consecutive_periods: 1,
  consecutive_count: 0,
  last_warning_at: null,
  measured_at: '2026-08-14T00:00:00.000Z',
  ...over,
});

test('sin límite no hay porcentaje, y eso no es cero', () => {
  assert.equal(quotaPercent(quota({ limit_value: null })), null);
  assert.equal(quotaHealth(quota({ limit_value: null })), 'nolimit');
});

test('sin medición no se inventa un consumo', () => {
  assert.equal(quotaHealth(quota({ current_value: null })), 'unmeasured');
});

test('el aviso usa el porcentaje configurado, no un 80 fijo', () => {
  assert.equal(quotaHealth(quota({ current_value: 55, warning_percent: 50 })), 'warn');
  assert.equal(quotaHealth(quota({ current_value: 55, warning_percent: 90 })), 'ok');
});

test('excedida es estrictamente por encima del límite', () => {
  assert.equal(isExceeded(quota({ current_value: 100 })), false);
  assert.equal(isExceeded(quota({ current_value: 101 })), true);
  assert.equal(isExceeded(quota({ limit_value: null, current_value: 999 })), false);
});

test('worstQuotaByInstance se queda con la más apretada y descarta las de contrato', () => {
  const worst = worstQuotaByInstance([
    quota({ id: 1, current_value: 20 }),
    quota({ id: 2, current_value: 95 }),
    quota({ id: 3, scope: 'subscription', instance_id: null, subscription_id: 7, current_value: 99 }),
  ]);
  assert.equal(worst.size, 1);
  assert.equal(worst.get(10)?.id, 2);
});
