import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import type { Deployment, Invoice } from '../../types'
import { useStore } from '../../store/DataStore'
import { useToast } from '../../notify/ToastProvider'
import {
  GraceModal,
  ResumeModal,
  ResumeOfferModal,
  SuspendModal,
} from './StateChangeModals'
import { OverdueModal, type OverdueChoice } from './OverdueModal'
import { TypedConfirmModal } from './TypedConfirmModal'

interface ModalApi {
  openSuspend: (dep: Deployment) => void
  openGrace: (dep: Deployment, mode?: 'grace' | 'extend') => void
  openResume: (dep: Deployment) => void
  openSuspendBatch: (deps: Deployment[]) => void
  openGraceBatch: (deps: Deployment[]) => void
  openResumeBatch: (deps: Deployment[]) => void
  openRotateSecret: (dep: Deployment) => void
  openDelete: (dep: Deployment, onDone?: () => void) => void
  openMarkOverdue: (inv: Invoice) => void
  /** Marks paid (toast) and offers a resume if the deployment was suspended. */
  markInvoicePaid: (inv: Invoice) => void
}

type Active =
  | { type: 'suspend'; deps: Deployment[] }
  | { type: 'grace'; deps: Deployment[]; mode: 'grace' | 'extend' }
  | { type: 'resume'; deps: Deployment[] }
  | { type: 'rotate'; dep: Deployment }
  | { type: 'delete'; dep: Deployment; onDone?: () => void }
  | { type: 'overdue'; inv: Invoice }
  | { type: 'resumeOffer'; dep: Deployment }
  | null

const Ctx = createContext<ModalApi | null>(null)

function summary(deps: Deployment[], verb: string): string {
  return deps.length === 1
    ? `${deps[0].name} ${verb}`
    : `${deps.length} deployments ${verb}`
}

export function ModalHost({ children }: { children: ReactNode }) {
  const store = useStore()
  const toast = useToast()
  const [active, setActive] = useState<Active>(null)
  const close = useCallback(() => setActive(null), [])

  const api = useMemo<ModalApi>(
    () => ({
      openSuspend: (dep) => setActive({ type: 'suspend', deps: [dep] }),
      openGrace: (dep, mode = 'grace') =>
        setActive({ type: 'grace', deps: [dep], mode }),
      openResume: (dep) => setActive({ type: 'resume', deps: [dep] }),
      openSuspendBatch: (deps) =>
        deps.length && setActive({ type: 'suspend', deps }),
      openGraceBatch: (deps) =>
        deps.length && setActive({ type: 'grace', deps, mode: 'grace' }),
      openResumeBatch: (deps) =>
        deps.length && setActive({ type: 'resume', deps }),
      openRotateSecret: (dep) => setActive({ type: 'rotate', dep }),
      openDelete: (dep, onDone) => setActive({ type: 'delete', dep, onDone }),
      openMarkOverdue: (inv) => setActive({ type: 'overdue', inv }),
      markInvoicePaid: (inv) => {
        store.markInvoicePaid(inv.id)
        toast.success(`${inv.number} marked paid`, inv.clientName)
        const dep = store.deployments.find((d) => d.id === inv.deploymentId)
        if (
          dep &&
          dep.state.status === 'suspended' &&
          dep.state.reason === 'payment_overdue'
        ) {
          setActive({ type: 'resumeOffer', dep })
        }
      },
    }),
    [store, toast],
  )

  const overdueDep =
    active?.type === 'overdue'
      ? store.deployments.find((d) => d.id === active.inv.deploymentId)
      : undefined

  return (
    <Ctx.Provider value={api}>
      {children}

      <SuspendModal
        open={active?.type === 'suspend'}
        targets={active?.type === 'suspend' ? active.deps : []}
        onClose={close}
        onConfirm={(reason, message) => {
          if (active?.type !== 'suspend') return
          active.deps.forEach((d) =>
            store.suspendDeployment(d.id, reason, message),
          )
          toast.success(summary(active.deps, 'suspended'), 'Users now receive HTTP 402.')
          close()
        }}
      />

      <GraceModal
        open={active?.type === 'grace'}
        targets={active?.type === 'grace' ? active.deps : []}
        mode={active?.type === 'grace' ? active.mode : 'grace'}
        onClose={close}
        onConfirm={(reason, message, graceUntilIso) => {
          if (active?.type !== 'grace') return
          active.deps.forEach((d) =>
            store.graceDeployment(d.id, reason, message, graceUntilIso),
          )
          toast.success(
            active.mode === 'extend'
              ? summary(active.deps, 'grace extended')
              : summary(active.deps, 'moved to grace'),
          )
          close()
        }}
      />

      <ResumeModal
        open={active?.type === 'resume'}
        targets={active?.type === 'resume' ? active.deps : []}
        onClose={close}
        onConfirm={() => {
          if (active?.type !== 'resume') return
          active.deps.forEach((d) => store.resumeDeployment(d.id))
          toast.success(summary(active.deps, 'resumed'))
          close()
        }}
      />

      <ResumeOfferModal
        open={active?.type === 'resumeOffer'}
        deployment={active?.type === 'resumeOffer' ? active.dep : null}
        onClose={close}
        onConfirm={() => {
          if (active?.type !== 'resumeOffer') return
          store.resumeDeployment(active.dep.id)
          toast.success(`${active.dep.name} resumed`)
          close()
        }}
      />

      <TypedConfirmModal
        open={active?.type === 'rotate'}
        label={active?.type === 'rotate' ? active.dep.subdomain : undefined}
        heading={
          active?.type === 'rotate'
            ? `Rotate control secret for ${active.dep.name}?`
            : ''
        }
        body={
          <p>
            Rotating immediately invalidates the previous secret. Any
            control-plane integration using it will stop working until updated.
          </p>
        }
        confirmWord={active?.type === 'rotate' ? active.dep.name : ''}
        confirmHelper="Type the deployment name to confirm."
        primaryText="Rotate secret"
        onClose={close}
        onConfirm={() => {
          if (active?.type !== 'rotate') return
          store.rotateSecret(active.dep.id)
          toast.success(
            'Control secret rotated',
            'The previous secret is now invalid.',
          )
          close()
        }}
      />

      <TypedConfirmModal
        open={active?.type === 'delete'}
        label={active?.type === 'delete' ? active.dep.subdomain : undefined}
        heading={
          active?.type === 'delete'
            ? `Delete deployment ${active.dep.name}?`
            : ''
        }
        body={
          <p>
            This permanently deletes the deployment, its database and all usage
            history. This cannot be undone.
          </p>
        }
        confirmWord={active?.type === 'delete' ? active.dep.name : ''}
        confirmHelper="Type the deployment name to confirm."
        primaryText="Delete deployment"
        onClose={close}
        onConfirm={() => {
          if (active?.type !== 'delete') return
          const { dep, onDone } = active
          store.removeDeployment(dep.id)
          toast.success(`${dep.name} deleted`)
          close()
          onDone?.()
        }}
      />

      <OverdueModal
        open={active?.type === 'overdue'}
        invoice={active?.type === 'overdue' ? active.inv : null}
        deploymentName={overdueDep?.name ?? ''}
        deploymentOperational={overdueDep?.state.status === 'active'}
        onClose={close}
        onChoice={(choice: OverdueChoice, graceUntilIso) => {
          if (active?.type !== 'overdue') return
          const inv = active.inv
          const enforcement =
            choice === 'grace'
              ? 'grace'
              : choice === 'suspend'
                ? 'suspended'
                : null
          store.markInvoiceOverdue(inv.id, enforcement)
          if (choice === 'grace') {
            store.graceDeployment(
              inv.deploymentId,
              'payment_overdue',
              `Your subscription payment for ${inv.number} is overdue. The API will be suspended at the deadline unless the balance is settled.`,
              graceUntilIso,
            )
            toast.success(`${inv.number} overdue`, `${overdueDep?.name} moved to grace`)
          } else if (choice === 'suspend') {
            store.suspendDeployment(
              inv.deploymentId,
              'payment_overdue',
              `Your subscription payment for ${inv.number} is overdue. The API has been suspended — settle the balance to restore service.`,
            )
            toast.success(`${inv.number} overdue`, `${overdueDep?.name} suspended`)
          } else {
            toast.success(`${inv.number} marked overdue`, 'Deployment kept running')
          }
          close()
        }}
      />
    </Ctx.Provider>
  )
}

export function useModals(): ModalApi {
  const ctx = useContext(Ctx)
  if (!ctx) throw new Error('useModals must be used within ModalHost')
  return ctx
}
