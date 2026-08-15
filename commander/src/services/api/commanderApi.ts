import { createApi, createStore } from './mock/commander-mock.js';
import type { ApiContext, CommanderApi } from './mock/commander-mock';
import { withWrites } from './mock/writes';
import type { WriteApi } from './mock/writes';
import type { Store } from './types';

/**
 * Punto único por el que la aplicación habla con el control plane.
 *
 * Hoy detrás hay un mock en memoria; cuando exista backend real, este módulo pasa a
 * hablar HTTP contra `/api/v1` y ninguna feature se entera, porque todas dependen de
 * `CommanderApiClient` y no de cómo se resuelve.
 */
export type CommanderApiClient = CommanderApi & WriteApi;

export type { ApiContext } from './mock/commander-mock';
export type { ApiError, ProvisionPayload } from './mock/commander-mock';

export interface CommanderApiHandle {
  client: CommanderApiClient;
  /** Estado del servidor simulado. Solo lo lee la búsqueda global y el arnés de simulación. */
  store: Store;
}

/**
 * `ctx` se muta en caliente (rol, latencia, fallo forzado) y el cliente lo lee en cada
 * llamada: por eso se pasa el mismo objeto, no una copia.
 */
export function createCommanderApi(ctx: ApiContext): CommanderApiHandle {
  const store = createStore();
  const client = withWrites(createApi(store, ctx), store, ctx);
  return { client, store };
}
