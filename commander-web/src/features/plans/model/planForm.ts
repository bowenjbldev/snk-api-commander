import type { Plan } from '@services/api/types';
import { asEntityStatus } from '@shared/lib/enums';
import { normalizeLimits } from './planLimits';
import type { PlanLimitDraft } from './planLimits';

export type PlanFormValues = {
  name: string;
  slug: string;
  description: string;
  /** Texto mientras se teclea: el precio lo valida el servidor, no el input. */
  monthly_price: string;
  status: string;
};

export function emptyPlanValues(plan?: Plan): PlanFormValues {
  return {
    name: plan?.name ?? '',
    slug: plan?.slug ?? '',
    description: plan?.description ?? '',
    monthly_price: plan ? String(plan.monthly_price) : '',
    status: plan?.status ?? 'active',
  };
}

export type PlanPayload = Partial<Plan> & { id?: number };

/** La aplicación queda fija tras crear: se envía siempre la que ya tenía el plan. */
export function toPlanPayload(
  values: PlanFormValues,
  limits: PlanLimitDraft[],
  applicationId: number,
  id?: number,
): PlanPayload {
  return {
    id,
    application_id: applicationId,
    name: values.name.trim(),
    slug: values.slug.trim(),
    description: values.description.trim(),
    // Un precio vacío no es cero: viaja como NaN para que el servidor lo rechace en vez
    // de dejar creado un plan gratis por descuido.
    monthly_price: values.monthly_price.trim() === '' ? NaN : Number(values.monthly_price),
    status: asEntityStatus(values.status),
    limits: normalizeLimits(limits),
  };
}
