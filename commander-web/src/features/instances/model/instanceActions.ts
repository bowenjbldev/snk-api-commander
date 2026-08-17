import type { Instance, ProvisioningStatus } from '@services/api/types';

export type InstanceAction = 'grace' | 'suspend' | 'resume' | 'change-plan' | 'retry' | 'deprovision';

/** Un trabajo en vuelo es el único motivo para sondear la ficha. */
export const IN_FLIGHT: ProvisioningStatus[] = ['pending', 'provisioning', 'deprovisioning'];

export function isInFlight(instance: Instance): boolean {
  return IN_FLIGHT.includes(instance.provisioning_status);
}

/**
 * Qué se le puede hacer a una instancia según su estado. Vive fuera de React porque
 * es regla de negocio: gracia y suspensión exigen `ready`, retry solo aplica a un
 * trabajo fallido o a una baja, y una baja ya hecha no se repite.
 */
export function availableActions(instance: Instance): InstanceAction[] {
  const actions: InstanceAction[] = [];
  if (instance.provisioning_status === 'ready') {
    actions.push('grace', 'suspend');
    if (instance.service_status !== 'active') actions.push('resume');
    actions.push('change-plan');
  }
  if (instance.provisioning_status === 'failed' || instance.provisioning_status === 'deprovisioned') {
    actions.push('retry');
  }
  if (!['deprovisioning', 'deprovisioned'].includes(instance.provisioning_status)) {
    actions.push('deprovision');
  }
  return actions;
}

/** Failed, suspendida o en gracia: lo que un operador debe mirar hoy. */
export function hasIncident(instance: Instance): boolean {
  return instance.provisioning_status === 'failed' || instance.service_status !== 'active';
}

/** El sondeo para a los diez minutos: el trabajo sigue, la consulta no. */
export const POLL_INTERVAL_MS = 5000;
export const POLL_MAX_TICKS = 120;
