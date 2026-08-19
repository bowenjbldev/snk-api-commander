import { useMemo } from 'react';
import { useApp } from '@app/provider';
import type { CommanderApiClient } from '@services/api/commanderApi';
import type { Application, Instance, Plan } from '@services/api/types';
import { toPlanPayload } from '../model/planForm';
import type { PlanFormValues } from '../model/planForm';
import type { PlanLimitDraft } from '../model/planLimits';

export type PlansData = [Plan[], Application[], Instance[]];

export interface PlansService {
  /**
   * Las instancias vienen con el listado porque el menú de fila necesita saber si el
   * plan está en uso: un plan con instancias se desactiva, no se borra.
   */
  load(can: (perm: string) => boolean): Promise<PlansData>;
  save(
    values: PlanFormValues,
    limits: PlanLimitDraft[],
    applicationId: number,
    id?: number,
  ): Promise<Plan>;
}

export function createPlansService(api: CommanderApiClient): PlansService {
  return {
    load: (can) =>
      Promise.all([
        api.list('plans'),
        api.list('applications'),
        can('index instances') ? api.list('instances') : Promise.resolve([]),
      ]),
    save: (values, limits, applicationId, id) =>
      api.savePlan(toPlanPayload(values, limits, applicationId, id)),
  };
}

export function usePlansService(): PlansService {
  const { api } = useApp();
  return useMemo(() => createPlansService(api), [api]);
}
