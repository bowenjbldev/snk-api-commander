import { useMemo } from 'react';
import { useApp } from '@app/provider';
import type { CommanderApiClient } from '@services/api/commanderApi';
import type { Application, Client, Instance, Plan } from '@services/api/types';

/**
 * Catálogo code-first: las aplicaciones y sus métricas las descubre
 * `commander:applications:sync`. No hay alta ni borrado desde el cliente web.
 */
export interface ApplicationsService {
  listWithCounts(): Promise<[Application[], Instance[], Plan[]]>;
  getWithContext(id: string | number): Promise<[Application, Plan[], Instance[], Client[]]>;
}

export function createApplicationsService(api: CommanderApiClient): ApplicationsService {
  return {
    listWithCounts: () => Promise.all([api.list('applications'), api.list('instances'), api.list('plans')]),
    getWithContext: (id) =>
      Promise.all([api.get('applications', id), api.list('plans'), api.list('instances'), api.list('clients')]),
  };
}

export function useApplicationsService(): ApplicationsService {
  const { api } = useApp();
  return useMemo(() => createApplicationsService(api), [api]);
}
