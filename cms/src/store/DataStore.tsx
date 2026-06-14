import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import {
  activity as seedActivity,
  deployments as seedDeployments,
  invoices as seedInvoices,
  quotas as seedQuotas,
} from '../data/dataset'
import type {
  ActivityEvent,
  Deployment,
  Invoice,
  InvoiceEnforcement,
  Quota,
  QuotaPolicy,
  StateReason,
} from '../types'
import { simNowMs } from '../lib/time'

interface StoreValue {
  deployments: Deployment[]
  invoices: Invoice[]
  quotas: Quota[]
  activity: ActivityEvent[]
  suspendDeployment: (id: string, reason: StateReason, message: string) => void
  graceDeployment: (
    id: string,
    reason: StateReason,
    message: string,
    graceUntilIso: string,
  ) => void
  resumeDeployment: (id: string) => void
  rotateSecret: (id: string) => void
  removeDeployment: (id: string) => void
  markInvoicePaid: (id: string) => void
  markInvoiceOverdue: (id: string, enforcement: InvoiceEnforcement) => void
  updateQuotaLimit: (quotaId: string, limit: number | null) => void
  updateQuotaPolicy: (quotaId: string, policy: QuotaPolicy) => void
}

const Ctx = createContext<StoreValue | null>(null)

const clone = <T,>(arr: T[]): T[] => arr.map((x) => ({ ...x }))
const nowIso = () => new Date(simNowMs()).toISOString()

export function DataStoreProvider({ children }: { children: ReactNode }) {
  const [deployments, setDeployments] = useState<Deployment[]>(() =>
    clone(seedDeployments),
  )
  const [invoices, setInvoices] = useState<Invoice[]>(() => clone(seedInvoices))
  const [quotas, setQuotas] = useState<Quota[]>(() => clone(seedQuotas))
  const [activity, setActivity] = useState<ActivityEvent[]>(() =>
    clone(seedActivity),
  )
  const evtSeq = useRef(1000)

  const pushActivity = useCallback((ev: Omit<ActivityEvent, 'id' | 'at'>) => {
    setActivity((cur) => [
      { ...ev, id: `a-${++evtSeq.current}`, at: nowIso() },
      ...cur,
    ])
  }, [])

  const patchDeployment = useCallback(
    (id: string, patch: Partial<Deployment>) => {
      setDeployments((cur) =>
        cur.map((d) => (d.id === id ? { ...d, ...patch } : d)),
      )
    },
    [],
  )

  const suspendDeployment = useCallback(
    (id: string, reason: StateReason, message: string) => {
      patchDeployment(id, {
        state: {
          status: 'suspended',
          reason,
          message,
          graceUntil: null,
          changedBy: 'operator',
          changedAt: nowIso(),
        },
      })
      pushActivity({
        kind: 'suspend',
        parts: [
          { text: id, mono: true, link: { kind: 'deployment', id } },
          { text: ' suspended — ' },
          { text: reason, mono: true },
          { text: ' · by operator' },
        ],
      })
    },
    [patchDeployment, pushActivity],
  )

  const graceDeployment = useCallback(
    (id: string, reason: StateReason, message: string, graceUntilIso: string) => {
      patchDeployment(id, {
        state: {
          status: 'grace',
          reason,
          message,
          graceUntil: graceUntilIso,
          changedBy: 'operator',
          changedAt: nowIso(),
        },
      })
      pushActivity({
        kind: 'grace',
        parts: [
          { text: id, mono: true, link: { kind: 'deployment', id } },
          { text: ' moved to grace period — ' },
          { text: reason, mono: true },
          { text: ' · by operator' },
        ],
      })
    },
    [patchDeployment, pushActivity],
  )

  const resumeDeployment = useCallback(
    (id: string) => {
      patchDeployment(id, {
        state: {
          status: 'active',
          reason: 'manual',
          message: '',
          graceUntil: null,
          changedBy: 'operator',
          changedAt: nowIso(),
        },
      })
      pushActivity({
        kind: 'resume',
        parts: [
          { text: id, mono: true, link: { kind: 'deployment', id } },
          { text: ' resumed — by operator' },
        ],
      })
    },
    [patchDeployment, pushActivity],
  )

  const rotateSecret = useCallback(
    (id: string) => {
      const hex = (++evtSeq.current).toString(16).slice(-4).padStart(4, '0')
      patchDeployment(id, {
        controlSecretLast4: hex,
        secretRotatedAt: nowIso(),
      })
    },
    [patchDeployment],
  )

  const removeDeployment = useCallback((id: string) => {
    setDeployments((cur) => cur.filter((d) => d.id !== id))
  }, [])

  const markInvoicePaid = useCallback(
    (id: string) => {
      setInvoices((cur) =>
        cur.map((i) =>
          i.id === id ? { ...i, status: 'paid', enforcement: null } : i,
        ),
      )
      const inv = invoices.find((i) => i.id === id)
      if (inv) {
        pushActivity({
          kind: 'paid',
          parts: [
            { text: inv.number, mono: true, link: { kind: 'invoice', id } },
            { text: ` marked paid — ${inv.clientName}` },
          ],
        })
      }
    },
    [invoices, pushActivity],
  )

  const markInvoiceOverdue = useCallback(
    (id: string, enforcement: InvoiceEnforcement) => {
      setInvoices((cur) =>
        cur.map((i) =>
          i.id === id ? { ...i, status: 'overdue', enforcement } : i,
        ),
      )
    },
    [],
  )

  const updateQuotaLimit = useCallback(
    (quotaId: string, limit: number | null) => {
      setQuotas((cur) =>
        cur.map((q) => (q.id === quotaId ? { ...q, limit } : q)),
      )
    },
    [],
  )

  const updateQuotaPolicy = useCallback(
    (quotaId: string, policy: QuotaPolicy) => {
      setQuotas((cur) =>
        cur.map((q) => (q.id === quotaId ? { ...q, onExceed: policy } : q)),
      )
    },
    [],
  )

  const value = useMemo<StoreValue>(
    () => ({
      deployments,
      invoices,
      quotas,
      activity,
      suspendDeployment,
      graceDeployment,
      resumeDeployment,
      rotateSecret,
      removeDeployment,
      markInvoicePaid,
      markInvoiceOverdue,
      updateQuotaLimit,
      updateQuotaPolicy,
    }),
    [
      deployments,
      invoices,
      quotas,
      activity,
      suspendDeployment,
      graceDeployment,
      resumeDeployment,
      rotateSecret,
      removeDeployment,
      markInvoicePaid,
      markInvoiceOverdue,
      updateQuotaLimit,
      updateQuotaPolicy,
    ],
  )

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}

export function useStore(): StoreValue {
  const ctx = useContext(Ctx)
  if (!ctx) throw new Error('useStore must be used within DataStoreProvider')
  return ctx
}
