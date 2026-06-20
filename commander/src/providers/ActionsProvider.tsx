import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useStore } from '../store/store'
import { useToast } from './ToastProvider'
import { StateChangeModal, type StateChangeResult } from '../components/modals/StateChangeModal'
import { TypedConfirmModal } from '../components/modals/TypedConfirmModal'
import { OverdueEnforcementModal } from '../components/modals/OverdueEnforcementModal'
import type { Deployment, Invoice } from '../store/types'

interface ActionsApi {
  suspend: (targets: Deployment | Deployment[]) => void
  grace: (targets: Deployment | Deployment[]) => void
  resume: (targets: Deployment | Deployment[]) => void
  deleteDeployment: (dep: Deployment, onDeleted?: () => void) => void
  markPaid: (invoice: Invoice) => void
  markOverdue: (invoice: Invoice) => void
}

const ActionsContext = createContext<ActionsApi | null>(null)

function asArray(t: Deployment | Deployment[]): Deployment[] {
  return Array.isArray(t) ? t : [t]
}

export function ActionsProvider({ children }: { children: ReactNode }) {
  const toast = useToast()
  // Reactive only for what the open modals render; mutations go through getState().
  const graceHoursDefault = useStore((s) => s.settings.default_grace_hours)
  const deployments = useStore((s) => s.deployments)
  const clients = useStore((s) => s.clients)

  // A background persist() that failed (server rejected / unreachable) surfaces here.
  const actionError = useStore((s) => s.actionError)
  useEffect(() => {
    if (actionError) toast.error('Action could not be saved', actionError.message)
  }, [actionError, toast])

  const [stateChange, setStateChange] = useState<{ mode: 'suspend' | 'grace'; targets: Deployment[] } | null>(null)
  const [typed, setTyped] = useState<
    { kind: 'delete'; dep: Deployment; onDeleted?: () => void } | null
  >(null)
  const [overdue, setOverdue] = useState<Invoice | null>(null)

  const noun = (n: number) => (n === 1 ? 'deployment' : 'deployments')

  const api = useMemo<ActionsApi>(
    () => ({
      suspend: (t) => setStateChange({ mode: 'suspend', targets: asArray(t) }),
      grace: (t) => setStateChange({ mode: 'grace', targets: asArray(t) }),
      resume: (t) => {
        const s = useStore.getState()
        const list = asArray(t)
        list.forEach((d) => s.resume(d.id))
        toast.success(
          list.length === 1 ? 'Deployment resumed' : `${list.length} deployments resumed`,
          list.length === 1 ? list[0].name : undefined,
        )
      },
      deleteDeployment: (dep, onDeleted) => setTyped({ kind: 'delete', dep, onDeleted }),
      markPaid: (invoice) => {
        const s = useStore.getState()
        const { resumableDeploymentId } = s.markInvoicePaid(invoice.id)
        toast.success('Invoice marked as paid', invoice.number)
        if (resumableDeploymentId) {
          const dep = s.deployments.find((d) => d.id === resumableDeploymentId)
          toast.info('Deployment can be resumed', `${dep?.name ?? resumableDeploymentId} was suspended for payment_overdue.`, {
            actionLabel: 'Resume deployment',
            onAction: () => {
              useStore.getState().resume(resumableDeploymentId)
              toast.success('Deployment resumed', dep?.name)
            },
          })
        }
      },
      markOverdue: (invoice) => {
        useStore.getState().markInvoiceOverdue(invoice.id)
        setOverdue(invoice)
      },
    }),
    [toast],
  )

  const overdueDep = overdue ? deployments.find((d) => d.id === overdue.deployment_id) : undefined
  const overdueClient = overdue ? clients.find((c) => c.id === overdue.client_id) : undefined

  const handleStateConfirm = (r: StateChangeResult) => {
    if (!stateChange) return
    const s = useStore.getState()
    const { mode, targets } = stateChange
    if (mode === 'suspend') {
      targets.forEach((d) => s.suspend(d.id, { reason: r.reason, message: r.message }))
      toast.success(
        targets.length === 1 ? 'Deployment suspended' : `${targets.length} ${noun(targets.length)} suspended`,
        targets.length === 1 ? targets[0].name : undefined,
      )
    } else {
      const graceUntil = new Date(Date.now() + (r.graceHours ?? graceHoursDefault) * 3_600_000).toISOString()
      targets.forEach((d) => s.grace(d.id, { reason: r.reason, message: r.message, grace_until: graceUntil }))
      toast.success(
        targets.length === 1 ? 'Moved to grace' : `${targets.length} ${noun(targets.length)} moved to grace`,
        targets.length === 1 ? targets[0].name : undefined,
      )
    }
    setStateChange(null)
  }

  return (
    <ActionsContext.Provider value={api}>
      {children}

      {stateChange && (
        <StateChangeModal
          mode={stateChange.mode}
          targets={stateChange.targets}
          defaultGraceHours={graceHoursDefault}
          open
          onClose={() => setStateChange(null)}
          onConfirm={handleStateConfirm}
        />
      )}

      {typed?.kind === 'delete' && (
        <TypedConfirmModal
          open
          title={`Delete deployment ${typed.dep.name}?`}
          label={typed.dep.subdomain}
          confirmWord={typed.dep.name}
          confirmLabel="Delete deployment"
          body={
            <p>
              This permanently removes the deployment, its quotas and subscription link. This cannot be undone.
            </p>
          }
          onClose={() => setTyped(null)}
          onConfirm={() => {
            const { dep, onDeleted } = typed
            useStore.getState().deleteDeployment(dep.id)
            toast.success('Deployment deleted', dep.name)
            setTyped(null)
            onDeleted?.()
          }}
        />
      )}

      {overdue && (
        <OverdueEnforcementModal
          open
          invoice={overdue}
          deployment={overdueDep}
          clientName={overdueClient?.name ?? String(overdue.client_id)}
          defaultGraceHours={graceHoursDefault}
          onClose={() => setOverdue(null)}
          onKeep={() => {
            useStore.getState().setInvoiceEnforcement(overdue.id, null)
            toast.info('Deployment left running', overdueDep?.name)
            setOverdue(null)
          }}
          onGrace={() => {
            const s = useStore.getState()
            if (overdueDep) {
              const graceUntil = new Date(Date.now() + graceHoursDefault * 3_600_000).toISOString()
              s.grace(overdueDep.id, { reason: 'payment_overdue', grace_until: graceUntil })
            }
            s.setInvoiceEnforcement(overdue.id, 'grace')
            toast.success('Moved to grace', overdueDep?.name)
            setOverdue(null)
          }}
          onSuspend={() => {
            const s = useStore.getState()
            if (overdueDep) s.suspend(overdueDep.id, { reason: 'payment_overdue' })
            s.setInvoiceEnforcement(overdue.id, 'suspended')
            toast.success('Deployment suspended', overdueDep?.name)
            setOverdue(null)
          }}
        />
      )}
    </ActionsContext.Provider>
  )
}

export function useActions(): ActionsApi {
  const ctx = useContext(ActionsContext)
  if (!ctx) throw new Error('useActions must be used within ActionsProvider')
  return ctx
}
