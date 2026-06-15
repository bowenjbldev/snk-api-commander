import type {
  ActivityEvent,
  ActivityKind,
  ChangedBy,
  DeploymentState,
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

// Builds a fresh DeploymentState for a transition, stamping changed_at = now.
// Valid transitions: active→grace, active→suspended, grace→suspended,
// grace→active, suspended→active. The caller enforces source validity.
export function buildState(status: DeploymentStatus, opts: StateOpts = {}): DeploymentState {
  return {
    status,
    reason: opts.reason ?? null,
    message: opts.message ?? null,
    grace_until: status === 'grace' ? opts.grace_until ?? null : null,
    changed_by: opts.by ?? 'operator',
    changed_at: new Date().toISOString(),
  }
}

export interface ActivityOpts {
  deployment_id?: string
  invoice_id?: string
  reason?: StateReason | null
  by?: ChangedBy
  message?: string
}

export function activity(kind: ActivityKind, opts: ActivityOpts = {}): ActivityEvent {
  return { id: nextId('act'), at: new Date().toISOString(), kind, ...opts }
}
