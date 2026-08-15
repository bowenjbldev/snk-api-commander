import type { Plan } from '@services/api/types';
import { normalizeLimits } from './planLimits';
import type { PlanLimitDraft } from './planLimits';

export type PlanFormValues = {
  name: string;
  slug: string;
  description: string;
  monthly_price: number | string;
  status: string;
};

export function emptyPlanValues(plan?: Plan): PlanFormValues {
  return {
    name: plan?.name ?? '',
    slug: plan?.slug ?? '',
    description: plan?.description ?? '',
    monthly_price: plan?.monthly_price ?? 0,
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
    monthly_price: Number(values.monthly_price),
    status: values.status === 'inactive' ? 'inactive' : 'active',
    limits: normalizeLimits(limits),
  };
}
