import { useMemo } from 'react';
import { useApp } from '@app/provider';
import type { CommanderApiClient } from '@services/api/commanderApi';
import type { Operator } from '@services/api/types';
import { toOperatorPayload } from '../model/operatorForm';
import type { OperatorFormValues } from '../model/operatorForm';

/**
 * Única puerta del dominio hacia la API. Los componentes no saben que por debajo
 * hay `api.list('operators')` ni cómo se llama el método de escritura.
 */
export interface OperatorsService {
  list(): Promise<Operator[]>;
  get(id: string | number): Promise<Operator>;
  save(values: OperatorFormValues, id?: number): Promise<Operator>;
  remove(id: number): Promise<void>;
}

export function createOperatorsService(api: CommanderApiClient): OperatorsService {
  return {
    list: () => api.list('operators'),
    get: (id) => api.get('operators', id),
    save: (values, id) => api.saveOperator(toOperatorPayload(values, id)),
    remove: (id) => api.deleteOperator(id),
  };
}

export function useOperatorsService(): OperatorsService {
  const { api } = useApp();
  return useMemo(() => createOperatorsService(api), [api]);
}
