import assert from 'node:assert/strict';
import { test } from 'node:test';
import { actionDialog, availableActions, hasIncident, isInFlight } from './instanceActions.ts';
import type { Instance } from '@services/api/types';

const instance = (over: Partial<Instance> = {}): Instance => ({
  id: 1, client_id: 1, account_id: 1, application_id: 1, plan_id: 1, subscription_id: 1,
  database_name: 'kk_x', database_user: 'kk_x', provisioning_status: 'ready', service_status: 'active',
  status_reason: null, status_message: null, grace_until: null, last_error: null,
  requests_24h: 0, last_activity_at: null, created_at: '', updated_at: '', provisioned_at: null,
  ...over,
});

const ctx = {
  clientName: 'Diario Norte', clientSlug: 'diario-norte', graceHours: 48,
  currentPlan: 'Base', planOptions: [{ value: '2', label: 'Pro' }],
};

test('una instancia lista y activa no ofrece reanudar', () => {
  const actions = availableActions(instance());
  assert.deepEqual(actions, ['grace', 'suspend', 'change-plan', 'deprovision']);
});

test('ninguna acción repite el estado en el que ya está', () => {
  assert.equal(availableActions(instance({ service_status: 'grace' })).includes('grace'), false);
  assert.equal(availableActions(instance({ service_status: 'suspended' })).includes('suspend'), false);
  assert.ok(availableActions(instance({ service_status: 'suspended' })).includes('resume'));
});

test('sin plan no hay plan que cambiar', () => {
  assert.equal(availableActions(instance({ plan_id: null })).includes('change-plan'), false);
});

test('retry solo existe para failed o deprovisioned', () => {
  assert.ok(availableActions(instance({ provisioning_status: 'failed' })).includes('retry'));
  assert.ok(availableActions(instance({ provisioning_status: 'deprovisioned' })).includes('retry'));
  assert.equal(availableActions(instance()).includes('retry'), false);
});

test('una baja ya hecha o en curso no se puede volver a dar de baja', () => {
  assert.equal(availableActions(instance({ provisioning_status: 'deprovisioned' })).includes('deprovision'), false);
  assert.equal(availableActions(instance({ provisioning_status: 'deprovisioning' })).includes('deprovision'), false);
});

test('una instancia no lista no ofrece control de servicio', () => {
  assert.deepEqual(availableActions(instance({ provisioning_status: 'provisioning' })), ['deprovision']);
});

test('incidencia es failed o cualquier servicio que no sea activo', () => {
  assert.equal(hasIncident(instance()), false);
  assert.equal(hasIncident(instance({ service_status: 'grace' })), true);
  assert.equal(hasIncident(instance({ provisioning_status: 'failed' })), true);
});

test('solo se sondea mientras hay trabajo en vuelo', () => {
  assert.equal(isInFlight(instance({ provisioning_status: 'pending' })), true);
  assert.equal(isInFlight(instance()), false);
});

test('la baja pide el slug del cliente y es destructiva', () => {
  const dialog = actionDialog('deprovision', instance(), ctx);
  assert.equal(dialog.danger, true);
  assert.ok(dialog.body.includes('diario-norte'));
  assert.equal(dialog.fields[0].placeholder, 'diario-norte');
});

test('la gracia parte de las horas configuradas por el operador', () => {
  assert.equal(actionDialog('grace', instance(), ctx).initial.hours, 48);
});

test('cambiar de plan obliga a elegir: la opción vacía encabeza y no hay preselección', () => {
  const dialog = actionDialog('change-plan', instance(), ctx);
  assert.deepEqual(dialog.fields[0].options?.[0], { value: '', label: 'Elige un plan' });
  assert.equal(dialog.initial.plan_id, '');
});
