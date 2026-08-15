import { ROLES } from './commander-mock.js';
import type { ApiContext, CommanderApi } from './commander-mock';
import type { Account, Client, Operator, Store } from '../types';

/**
 * La API real de Commander expone `apiResource` completo para cuentas, clientes y
 * usuarios, pero `commander-mock.js` solo implementa las altas. Estas escrituras
 * viven aquí, fuera del fichero copiado del prototipo, para poder seguir
 * comparándolo con el original sin ruido.
 */
export interface WriteApi {
  updateAccount(id: number, data: Partial<Account>): Promise<Account>;
  updateClient(id: number, data: Partial<Client>): Promise<Client>;
  saveOperator(data: Partial<Operator> & { id?: number; password?: string }): Promise<Operator>;
  deleteOperator(id: number): Promise<void>;
}



interface MockError extends Error {
  status: number;
  fields: Record<string, string> | null;
}

const fail = (status: number, message: string, fields?: Record<string, string>): never => {
  const error = new Error(message) as MockError;
  error.status = status;
  error.fields = fields ?? null;
  throw error;
};

const EMAIL = /^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i;

export function withWrites(api: CommanderApi, store: Store, ctx: ApiContext): CommanderApi & WriteApi {
  const wait = () =>
    new Promise((resolve) =>
      setTimeout(resolve, ctx.latency === 'slow' ? 1400 + Math.random() * 900 : 260 + Math.random() * 320),
    );

  const guard = (perm: string) => {
    const perms = ROLES[ctx.role].perms;
    if (!perms.includes('*') && !perms.includes(perm)) fail(403, `Tu rol no tiene el permiso «${perm}».`);
  };

  async function call<T>(perm: string, fn: () => T): Promise<T> {
    guard(perm);
    await wait();
    if (ctx.fail) fail(500, 'El servidor no respondió a la petición.');
    return fn();
  }

  return {
    ...api,

    updateAccount(id, data) {
      return call('update accounts', () => {
        const account = store.accounts.find((a) => a.id === id);
        if (!account) fail(404, 'No encontrado.');
        const fields: Record<string, string> = {};
        if (!data.name || data.name.trim().length < 3) fields.name = 'El nombre necesita al menos 3 caracteres.';
        if (!data.email || !EMAIL.test(data.email)) fields.email = 'Formato de correo no válido.';
        if (data.billing_email && !EMAIL.test(data.billing_email)) fields.billing_email = 'Formato de correo no válido.';
        if (data.tax_id && !/^[A-Z]\d{8}$/i.test(data.tax_id)) fields.tax_id = 'NIF con formato letra + 8 dígitos.';
        if (Object.keys(fields).length) fail(422, 'No se pudo guardar la cuenta.', fields);
        Object.assign(account!, data, { updated_at: new Date().toISOString() });
        return account!;
      });
    },

    updateClient(id, data) {
      return call('update clients', () => {
        const client = store.clients.find((c) => c.id === id);
        if (!client) fail(404, 'No encontrado.');
        const fields: Record<string, string> = {};
        if (!data.name) fields.name = 'El nombre es obligatorio.';
        if (!data.email || !EMAIL.test(data.email)) fields.email = 'Formato de correo no válido.';
        if (Object.keys(fields).length) fail(422, 'No se pudo guardar el cliente.', fields);
        // Cuenta y slug quedan inmutables tras el alta: el subdominio ya está en uso.
        const { account_id: _account, slug: _slug, ...editable } = data;
        Object.assign(client!, editable, { updated_at: new Date().toISOString() });
        return client!;
      });
    },

    saveOperator(data) {
      return call(data.id ? 'update users' : 'create users', () => {
        const fields: Record<string, string> = {};
        if (!data.name) fields.name = 'El nombre completo es obligatorio.';
        if (!data.email || !EMAIL.test(data.email)) fields.email = 'Formato de correo no válido.';
        const clash = store.operators.find((o) => o.email === data.email && o.id !== data.id);
        if (clash) fields.email = 'Ese correo ya pertenece a otro operador.';
        if (!data.id && (data.password ?? '').length < 12) {
          fields.password = 'Al menos doce caracteres.';
        }
        if (Object.keys(fields).length) fail(422, 'No se pudo guardar el operador.', fields);

        const { password: _password, ...rest } = data;
        if (data.id) {
          const operator = store.operators.find((o) => o.id === data.id);
          if (!operator) fail(404, 'No encontrado.');
          Object.assign(operator!, rest);
          return operator!;
        }
        const operator = {
          id: store.seq.operator++,
          role: 'admin',
          company: '',
          phone: '',
          short_name: '',
          created_at: new Date().toISOString(),
          ...rest,
        } as Operator;
        store.operators.push(operator);
        return operator;
      });
    },

    deleteOperator(id) {
      return call('delete users', () => {
        // La sesión activa no puede eliminarse a sí misma; el backend debe repetir la comprobación.
        if (store.operators[0]?.id === id) {
          fail(422, 'No puedes eliminar el operador con el que has iniciado sesión.');
        }
        const index = store.operators.findIndex((o) => o.id === id);
        if (index === -1) fail(404, 'No encontrado.');
        store.operators.splice(index, 1);
      });
    },
  };
}
