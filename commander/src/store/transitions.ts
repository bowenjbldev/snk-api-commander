import type {
  ActivityEvent,
  ActivityKind,
  ChangedBy,
  DeploymentPackage,
  DeploymentStatus,
  StateReason,
} from './types'

let seq = 0

export function nextId(prefix: string): string {
  seq += 1
  return `${prefix}_${Date.now().toString(36)}_${seq}`
}

export interface StateOpts {
  reason?: StateReason | null
  message?: string | null
  grace_until?: string | null
  by?: ChangedBy
}

// Aplica una transición de estado sobre la instancia `package` anidada, sellando changed_at = now y
// conservando la identidad (id/name/subdomain/…). Transiciones válidas: active→grace, active→suspended,
// grace→suspended, grace→active, suspended→active. El caller valida el origen.
export function applyPackageState(
  pkg: DeploymentPackage,
  status: DeploymentStatus,
  opts: StateOpts = {},
): DeploymentPackage {
  return {
    ...pkg,
    status,
    reason: opts.reason ?? null,
    message: opts.message ?? null,
    grace_until: status === 'grace' ? opts.grace_until ?? null : null,
    changed_by: opts.by ?? 'operator',
    changed_at: new Date().toISOString(),
  }
}

export interface ActivityOpts {
  deployment_id?: number
  invoice_id?: number
  reason?: StateReason | null
  by?: ChangedBy
  message?: string
}

// Optimistic activity events use a transient negative id until loadData() replaces them with the
// server's bigint-keyed feed.
let tempActId = 0

export function activity(kind: ActivityKind, opts: ActivityOpts = {}): ActivityEvent {
  return { id: --tempActId, at: new Date().toISOString(), kind, ...opts }
}
