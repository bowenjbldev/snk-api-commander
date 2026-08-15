import { useMemo } from 'react';
import { useApp } from '@app/provider';
import type { CommanderApiClient } from '@services/api/commanderApi';
import type { Activity, Client, Instance } from '@services/api/types';

/** Auditoría operativa: solo lectura, la API no expone escritura ni borrado. */
export interface ActivityService {
  list(): Promise<Activity[]>;
  get(id: string | number): Promise<Activity>;
  /** El listado resuelve el nombre del cliente de cada evento; por eso viajan juntos. */
  listWithContext(): Promise<[Activity[], Instance[], Client[]]>;
}

export function createActivityService(api: CommanderApiClient): ActivityService {
  return {
    list: () => api.list('activity'),
    get: (id) => api.get('activity', id),
    listWithContext: () => Promise.all([api.list('activity'), api.list('instances'), api.list('clients')]),
  };
}

export function useActivityService(): ActivityService {
  const { api } = useApp();
  return useMemo(() => createActivityService(api), [api]);
}
