import { useMemo } from 'react';
import { useApp } from '@app/provider';
import type { CommanderApiClient } from '@services/api/commanderApi';
import type { Account, Application, Client, Instance, LogEntry } from '@services/api/types';

/**
 * Los logs no se borran ni se exportan: esos endpoints no existen y la vista no
 * debe insinuar que sí. Pueden desaparecer por retención o por baja de la instancia.
 */
export interface LogsService {
  listGlobal(): Promise<[LogEntry[], Instance[], Client[], Application[], Account[]]>;
}

export function createLogsService(api: CommanderApiClient): LogsService {
  return {
    listGlobal: () =>
      Promise.all([
        api.list('logs'),
        api.list('instances'),
        api.list('clients'),
        api.list('applications'),
        api.list('accounts'),
      ]),
  };
}

export function useLogsService(): LogsService {
  const { api } = useApp();
  return useMemo(() => createLogsService(api), [api]);
}
