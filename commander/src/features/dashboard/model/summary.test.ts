import assert from 'node:assert/strict';
import { test } from 'node:test';
import { buildSummary } from './summary.ts';
import type { Instance, Invoice, Quota, Subscription } from '@services/api/types';

const instance = (over: Partial<Instance> = {}): Instance => ({
  id: 1, client_id: 1, account_id: 1, application_id: 1, plan_id: 1, subscription_id: 1,
  database_name: '', database_user: '', provisioning_status: 'ready', service_status: 'active',
  status_reason: null, status_message: null, grace_until: null, last_error: null,
  requests_24h: 100, last_activity_at: null, created_at: '', updated_at: '', provisioned_at: null, ...over,
});

test('el MRR solo suma contratos active y trialing', () => {
  const subs = [
    { status: 'active', monthly_amount: 100 },
    { status: 'trialing', monthly_amount: 50 },
    { status: 'canceled', monthly_amount: 999 },
    { status: 'past_due', monthly_amount: 777 },
  ] as Subscription[];
  assert.equal(buildSummary([], [], [], subs, [], []).mrr, 150);
});

test('una instancia en gracia cuenta como incidencia aunque esté ready', () => {
  const summary = buildSummary([], [], [instance(), instance({ id: 2, service_status: 'grace' })], [], [], []);
  assert.equal(summary.readyInstances.length, 2);
  assert.equal(summary.incidentInstances.length, 1);
});

test('requests 24 h suma todo el parque', () => {
  assert.equal(buildSummary([], [], [instance(), instance({ id: 2, requests_24h: 50 })], [], [], []).requests24h, 150);
});

test('solo las vencidas suman al total pendiente', () => {
  const invoices = [
    { status: 'overdue', total: 100 },
    { status: 'paid', total: 900 },
  ] as Invoice[];
  const summary = buildSummary([], [], [], [], [], invoices);
  assert.equal(summary.overdueInvoices.length, 1);
  assert.equal(summary.overdueTotal, 100);
});

test('las cuotas top se ordenan por porcentaje y descartan las que no tienen límite', () => {
  const quotas = [
    { limit_value: 100, current_value: 10, warning_percent: 80 },
    { limit_value: 100, current_value: 90, warning_percent: 80 },
    { limit_value: null, current_value: 999, warning_percent: null },
  ] as Quota[];
  const top = buildSummary([], [], [], [], quotas, []).topQuotas;
  assert.equal(top.length, 2);
  assert.equal(top[0].current_value, 90);
});
