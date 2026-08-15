import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createApi, createStore } from './commander-mock.js';
import type { ApiContext, ApiError } from './commander-mock.js';
import { withWrites } from './writes.ts';

function build(role = 'admin') {
  const store = createStore();
  const ctx: ApiContext = { latency: 'normal', fail: false, role };
  return { store, api: withWrites(createApi(store, ctx), store, ctx) };
}

async function rejects(fn: () => Promise<unknown>): Promise<ApiError> {
  try {
    await fn();
  } catch (e) {
    return e as ApiError;
  }
  throw new Error('se esperaba un error y la llamada terminó bien');
}

test('updateAccount devuelve 422 con el campo exacto cuando el NIF no vale', async () => {
  const { api, store } = build();
  const error = await rejects(() =>
    api.updateAccount(store.accounts[0].id, { name: 'Nombre válido', email: 'a@b.es', tax_id: 'X1' }),
  );
  assert.equal(error.status, 422);
  assert.equal(error.fields?.tax_id, 'NIF con formato letra + 8 dígitos.');
});

test('updateClient no deja mover el cliente de cuenta ni cambiar su subdominio', async () => {
  const { api, store } = build();
  const client = store.clients[0];
  const originalSlug = client.slug;
  const originalAccount = client.account_id;

  const saved = await api.updateClient(client.id, {
    name: 'Renombrado',
    email: 'nuevo@cliente.es',
    slug: 'otro-slug',
    account_id: originalAccount + 1,
  });

  assert.equal(saved.name, 'Renombrado');
  assert.equal(saved.slug, originalSlug, 'el subdominio es inmutable tras el alta');
  assert.equal(saved.account_id, originalAccount, 'la cuenta pagadora es inmutable tras el alta');
});

test('saveOperator rechaza un correo ya usado por otro operador', async () => {
  const { api, store } = build();
  const error = await rejects(() =>
    api.saveOperator({ name: 'Alguien Nuevo', email: store.operators[1].email, password: 'doceletras12' }),
  );
  assert.equal(error.status, 422);
  assert.match(error.fields?.email ?? '', /ya pertenece/);
});

test('deleteOperator no permite borrar la sesión activa', async () => {
  const { api, store } = build();
  const error = await rejects(() => api.deleteOperator(store.operators[0].id));
  assert.equal(error.status, 422);
  assert.equal(store.operators.length, 4, 'nadie desaparece del store');
});

test('un rol sin permiso recibe 403 antes de tocar el store', async () => {
  const { api, store } = build('lectura');
  const name = store.accounts[0].name;
  const error = await rejects(() =>
    api.updateAccount(store.accounts[0].id, { name: 'Cambiado', email: 'a@b.es' }),
  );
  assert.equal(error.status, 403);
  assert.equal(store.accounts[0].name, name, 'el guard corta antes de escribir');
});
