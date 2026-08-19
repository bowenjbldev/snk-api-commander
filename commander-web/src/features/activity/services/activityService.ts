import { useMemo } from 'react';
import { useApp } from '@app/provider';
import type { CommanderApiClient } from '@services/api/commanderApi';
import type { Account, Activity } from '@services/api/types';

/** Auditoría operativa: solo lectura, la API no expone escritura ni borrado. */
export interface ActivityService {
  /** El filtro por cuenta necesita los nombres: el evento solo trae `account_id`. */
  listWithContext(): Promise<[Activity[], Account[]]>;
}

export function createActivityService(api: CommanderApiClient): ActivityService {
  return {
    listWithContext: () => Promise.all([api.list('activity'), api.list('accounts')]),
  };
}

export function useActivityService(): ActivityService {
  const { api } = useApp();
  return useMemo(() => createActivityService(api), [api]);
}
