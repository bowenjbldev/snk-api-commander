import { useMemo } from 'react';
import { useApp } from '@app/provider';
import type { CommanderApiClient } from '@services/api/commanderApi';
import type { Application, Plan } from '@services/api/types';
import { toPlanPayload } from '../model/planForm';
import type { PlanFormValues } from '../model/planForm';
import type { PlanLimitDraft } from '../model/planLimits';

export interface PlansService {
  listWithApplications(): Promise<[Plan[], Application[]]>;
  save(values: PlanFormValues, limits: PlanLimitDraft[], applicationId: number, id?: number): Promise<Plan>;
}

export function createPlansService(api: CommanderApiClient): PlansService {
  return {
    listWithApplications: () => Promise.all([api.list('plans'), api.list('applications')]),
    save: (values, limits, applicationId, id) => api.savePlan(toPlanPayload(values, limits, applicationId, id)),
  };
}

export function usePlansService(): PlansService {
  const { api } = useApp();
  return useMemo(() => createPlansService(api), [api]);
}
