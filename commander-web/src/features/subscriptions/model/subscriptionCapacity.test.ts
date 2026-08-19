import assert from 'node:assert/strict';
import { test } from 'node:test';
import { activeItemsBySubscription, isCapacityFull, isSubscriptionDeletable, linesReferencing } from './subscriptionCapacity.ts';
import type { Invoice, Subscription, SubscriptionItem } from '@services/api/types';

const item = (over: Partial<SubscriptionItem> = {}): SubscriptionItem => ({
  id: 1, subscription_id: 5, client_id: 1, instance_id: 1, plan_id: 1,
  status: 'active', started_at: '', ended_at: null, ...over,
});

test('solo las cabeceras activas cuentan para la capacidad', () => {
  const counts = activeItemsBySubscription([item(), item({ id: 2 }), item({ id: 3, status: 'terminated' })]);
  assert.equal(counts.get(5), 2);
});

test('la capacidad se agota al igualar el máximo, no al superarlo', () => {
  const sub = { max_instances: 3 } as Subscription;
  assert.equal(isCapacityFull(sub, 2), false);
  assert.equal(isCapacityFull(sub, 3), true);
});

test('un contrato con cualquier historia no se borra', () => {
  assert.equal(isSubscriptionDeletable([], [], []), true);
  assert.equal(isSubscriptionDeletable([item()], [], []), false);
});

test('linesReferencing devuelve líneas, no facturas, y las identifica por posición', () => {
  const invoices = [
    { id: 1, lines: [{ subscription_id: 9 }, { subscription_id: 5 }] },
    { id: 2, lines: [{ subscription_id: 5 }] },
  ] as unknown as Invoice[];
  assert.deepEqual(linesReferencing(invoices, 5).map((l) => l.id), ['1-1', '2-0']);
});
