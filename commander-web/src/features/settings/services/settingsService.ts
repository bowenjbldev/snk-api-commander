import { useMemo } from 'react';
import { useApp } from '@app/provider';
import type { CommanderApiClient } from '@services/api/commanderApi';
import type { Settings } from '@services/api/types';
import { toSettingsPayload } from '../model/settingsForm';
import type { SettingsFormValues } from '../model/settingsForm';

export interface SettingsService {
  save(values: SettingsFormValues): Promise<Settings>;
}

export function createSettingsService(api: CommanderApiClient): SettingsService {
  return { save: (values) => api.saveSettings(toSettingsPayload(values)) };
}

export function useSettingsService(): SettingsService {
  const { api } = useApp();
  return useMemo(() => createSettingsService(api), [api]);
}
