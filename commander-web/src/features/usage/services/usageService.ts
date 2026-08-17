import { useMemo } from 'react';
import { useApp } from '@app/provider';
import type { CommanderApiClient } from '@services/api/commanderApi';
import type { Account, Application, Client, Instance, UsageBucket } from '@services/api/types';

export interface UsageService {
  loadPlatform(): Promise<[UsageBucket[], Instance[], Client[], Account[], Application[]]>;
}

export function createUsageService(api: CommanderApiClient): UsageService {
  return {
    loadPlatform: () =>
      Promise.all([
        api.list('usage'),
        api.list('instances'),
        api.list('clients'),
        api.list('accounts'),
        api.list('applications'),
      ]),
  };
}

export function useUsageService(): UsageService {
  const { api } = useApp();
  return useMemo(() => createUsageService(api), [api]);
}
