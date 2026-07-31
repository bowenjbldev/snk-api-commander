import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useStore, useProvisionedPackages } from '../store/store'
import { useToast } from './ToastProvider'
import { StateChangeModal, type StateChangeResult } from '../components/modals/StateChangeModal'
import { TypedConfirmModal } from '../components/modals/TypedConfirmModal'
import { OverdueEnforcementModal } from '../components/modals/OverdueEnforcementModal'
import type { Invoice, Package } from '../store/types'
import { packageFqdn } from '../lib/package'

interface ActionsApi {
  suspend: (targets: Package | Package[]) => void
  grace: (targets: Package | Package[]) => void
  resume: (targets: Package | Package[]) => void
  deprovision: (pkg: Package, onDeleted?: () => void) => void
  markPaid: (invoice: Invoice) => void
  markOverdue: (invoice: Invoice) => void
}

const ActionsContext = createContext<ActionsApi | null>(null)

function asArray(t: Package | Package[]): Package[] {
  return Array.isArray(t) ? t : [t]
}

export function ActionsProvider({ children }: { children: ReactNode }) {
  const toast = useToast()
  // Reactive only for what the open modals render; mutations go through getState().
  const graceHoursDefault = useStore((s) => s.settings.default_grace_hours)
  const packages = useProvisionedPackages()
  const clients = useStore((s) => s.clients)

  // A background persist() that failed (server rejected / unreachable) surfaces here.
  const actionError = useStore((s) => s.actionError)
  useEffect(() => {
    if (actionError) toast.error('Action could not be saved', actionError.message)
  }, [actionError, toast])

  const [stateChange, setStateChange] = useState<{ mode: 'suspend' | 'grace'; targets: Package[] } | null>(null)
  const [typed, setTyped] = useState<
    { kind: 'deprovision'; pkg: Package; onDeleted?: () => void } | null
  >(null)
  const [overdue, setOverdue] = useState<Invoice | null>(null)

  const noun = (n: number) => (n === 1 ? 'package' : 'packages')

  const api = useMemo<ActionsApi>(
    () => ({
      suspend: (t) => setStateChange({ mode: 'suspend', targets: asArray(t) }),
      grace: (t) => setStateChange({ mode: 'grace', targets: asArray(t) }),
      resume: (t) => {
        const s = useStore.getState()
        const list = asArray(t)
        list.forEach((p) => s.resume(p.id))
        toast.success(
          list.length === 1 ? 'Package resumed' : `${list.length} packages resumed`,
          list.length === 1 ? list[0].name : undefined,
        )
      },
      deprovision: (pkg, onDeleted) => setTyped({ kind: 'deprovision', pkg, onDeleted }),
      markPaid: (invoice) => {
        const s = useStore.getState()
        const { resumablePackageId, resumablePackageName } = s.markInvoicePaid(invoice.id)
        toast.success('Invoice marked as paid', invoice.number)
        if (resumablePackageId != null && resumablePackageName) {
          toast.info('Package can be resumed', `${resumablePackageName} was suspended for payment_overdue.`, {
            actionLabel: 'Resume package',
            onAction: () => {
              useStore.getState().resume(resumablePackageId)
              toast.success('Package resumed', resumablePackageName)
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

  const overduePackage = overdue ? packages.find((p) => p.id === overdue.package_id) : undefined
  const overdueClient = overdue ? clients.find((c) => c.id === overdue.client_id) : undefined

  const handleStateConfirm = (r: StateChangeResult) => {
    if (!stateChange) return
    const s = useStore.getState()
    const { mode, targets } = stateChange
    if (mode === 'suspend') {
      targets.forEach((p) => s.suspend(p.id, { reason: r.reason, message: r.message }))
      toast.success(
        targets.length === 1 ? 'Package suspended' : `${targets.length} ${noun(targets.length)} suspended`,
        targets.length === 1 ? targets[0].name : undefined,
      )
    } else {
      const graceUntil = new Date(Date.now() + (r.graceHours ?? graceHoursDefault) * 3_600_000).toISOString()
      targets.forEach((p) => s.grace(p.id, { reason: r.reason, message: r.message, grace_until: graceUntil }))
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

      {typed?.kind === 'deprovision' && (
        <TypedConfirmModal
          open
          title={`De-provision ${typed.pkg.name}?`}
          label={packageFqdn(typed.pkg)}
          confirmWord={typed.pkg.name}
          confirmLabel="De-provision package"
          body={
            <p>
              This permanently removes the package's live instance — its database, quotas and subscription
              link. This cannot be undone.
            </p>
          }
          onClose={() => setTyped(null)}
          onConfirm={() => {
            const { pkg, onDeleted } = typed
            useStore.getState().deprovision(pkg.id)
            toast.success('Package de-provisioned', pkg.name)
            setTyped(null)
            onDeleted?.()
          }}
        />
      )}

      {overdue && (
        <OverdueEnforcementModal
          open
          invoice={overdue}
          pkg={overduePackage}
          clientName={overdueClient?.name ?? String(overdue.client_id)}
          defaultGraceHours={graceHoursDefault}
          onClose={() => setOverdue(null)}
          onKeep={() => {
            useStore.getState().setInvoiceEnforcement(overdue.id, null)
            toast.info('Package left running', overduePackage?.name)
            setOverdue(null)
          }}
          onGrace={() => {
            const s = useStore.getState()
            if (overduePackage) {
              const graceUntil = new Date(Date.now() + graceHoursDefault * 3_600_000).toISOString()
              s.grace(overduePackage.id, { reason: 'payment_overdue', grace_until: graceUntil })
            }
            s.setInvoiceEnforcement(overdue.id, 'grace')
            toast.success('Moved to grace', overduePackage?.name)
            setOverdue(null)
          }}
          onSuspend={() => {
            const s = useStore.getState()
            if (overduePackage) s.suspend(overduePackage.id, { reason: 'payment_overdue' })
            s.setInvoiceEnforcement(overdue.id, 'suspended')
            toast.success('Package suspended', overduePackage?.name)
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
