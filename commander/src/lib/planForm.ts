import type { Plan } from '../store/types'
import { optionalLimit, slugify } from './input'

export interface PlanDraft {
  name: string
  description: string
  price: string
  qReq: string
  qDb: string
  qStorage: string
  qUsers: string
  active: boolean
}

export const planDraft = (plan?: Plan): PlanDraft => ({
  name: plan?.name ?? '',
  description: plan?.description ?? '',
  price: String(plan?.price_monthly ?? 0),
  qReq: plan?.q_requests_day == null ? '' : String(plan.q_requests_day),
  qDb: plan?.q_disk_db_mb == null ? '' : String(plan.q_disk_db_mb),
  qStorage: plan?.q_disk_storage_mb == null ? '' : String(plan.q_disk_storage_mb),
  qUsers: plan?.q_users_total == null ? '' : String(plan.q_users_total),
  active: plan ? plan.status === 'active' : true,
})

export const planPayload = (packageName: string, draft: PlanDraft): Omit<Plan, 'id' | 'slug'> & { slug: string } => ({
  slug: `${slugify(packageName)}-${slugify(draft.name)}`,
  package: packageName,
  name: draft.name.trim(),
  description: draft.description.trim() || null,
  price_monthly: Number(draft.price) || 0,
  status: draft.active ? 'active' : 'inactive',
  q_requests_day: optionalLimit(draft.qReq),
  q_disk_db_mb: optionalLimit(draft.qDb),
  q_disk_storage_mb: optionalLimit(draft.qStorage),
  q_users_total: optionalLimit(draft.qUsers),
})
