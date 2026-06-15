import { useMemo } from 'react'
import { create } from 'zustand'
import { buildFixtures, type Dataset } from './fixtures'
import { activity, buildState, nextId, type StateOpts } from './transitions'
import { api } from '../lib/api'
import type {
  ActivityEvent,
  Client,
  Deployment,
  Invoice,
  LogEntry,
  OnExceed,
  OperatorSettings,
  QuotaMetric,
  Subscription,
} from './types'

export interface SuspendInput {
  reason: string
  message?: string
  by?: StateOpts['by']
}
export interface GraceInput {
  grace_until: string
  reason: string
  message?: string
  by?: StateOpts['by']
}

interface StoreActions {
  // ── data lifecycle (API-backed) ──
  loaded: boolean
  actionError: { message: string; at: number } | null
  loadData: () => Promise<void>

  // ── deployment state machine ──
  suspend: (id: string, input: SuspendInput) => void
  grace: (id: string, input: GraceInput) => void
  resume: (id: string, by?: StateOpts['by']) => void
  rotateSecret: (id: string) => Promise<string | null> // server-generated; returns the new suffix

  // ── quotas ──
  editQuotaLimit: (quotaId: string, limit: number | null) => void
  setQuotaPolicy: (quotaId: string, policy: OnExceed) => void

  // ── billing ──
  markInvoicePaid: (invoiceId: string) => { resumableDeploymentId?: string }
  markInvoiceOverdue: (invoiceId: string) => void
  setInvoiceEnforcement: (invoiceId: string, enforcement: Invoice['enforcement']) => void

  // ── live tail ──
  prependLog: (entry: LogEntry) => void

  // ── CRUD ──
  addClient: (client: Omit<Client, 'id' | 'created_at'>) => string
  updateClient: (id: string, patch: Partial<Client>) => void
  createDeployment: (input: {
    name: string
    client_id: string
    package: string
    version: string
  }) => string
  deleteDeployment: (id: string) => void
  updateSettings: (patch: Partial<OperatorSettings>) => void
}

export type StoreState = Dataset & StoreActions

const initial = buildFixtures(Date.now())

function slug(name: string): string {
  return name
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/(^-|-$)/g, '')
}

export const useStore = create<StoreState>((set, get) => {
  // Fire the matching API call behind an optimistic local mutation. On success with an entity
  // body, reconcile (server is authoritative); on failure, surface a toast + resync from the API.
  const fail = (message: string) => set({ actionError: { message, at: Date.now() } })
  const persist = <T,>(p: Promise<T>, reconcile?: (entity: T) => void): void => {
    p.then((entity) => {
      if (reconcile && entity && typeof entity === 'object' && 'id' in (entity as object)) {
        reconcile(entity)
      }
    }).catch((e) => {
      fail(e instanceof Error ? e.message : 'Request failed')
      void get().loadData()
    })
  }
  const replaceDep = (d: Deployment) =>
    set((s) => ({
      deployments: s.deployments.map((x) => {
        if (x.id !== d.id) return x
        // Drop an out-of-order reconcile: a response older than our current state would otherwise
        // overwrite a newer optimistic mutation (two rapid actions on the same deployment).
        const incoming = d.state?.changed_at
        const current = x.state?.changed_at
        if (incoming && current && incoming < current) return x
        return d
      }),
    }))
  const upsertDep = (d: Deployment) =>
    set((s) => ({
      deployments: s.deployments.some((x) => x.id === d.id)
        ? s.deployments.map((x) => (x.id === d.id ? d : x))
        : [...s.deployments, d],
    }))
  const replaceInvoice = (i: Invoice) =>
    set((s) => ({ invoices: s.invoices.map((x) => (x.id === i.id ? i : x)) }))
  const replaceClient = (c: Client) =>
    set((s) => ({ clients: s.clients.map((x) => (x.id === c.id ? c : x)) }))
  const upsertClient = (c: Client) =>
    set((s) => ({
      clients: s.clients.some((x) => x.id === c.id)
        ? s.clients.map((x) => (x.id === c.id ? c : x))
        : [...s.clients, c],
    }))

  return {
    ...initial,
    loaded: false,
    actionError: null,

    loadData: async () => {
      try {
        const [clients, deployments, subscriptions, invoices, quotas, activityFeed] = await Promise.all([
          api<Client[]>('/clients'),
          api<Deployment[]>('/deployments'),
          api<Subscription[]>('/subscriptions'),
          api<Invoice[]>('/invoices'),
          api<QuotaMetric[]>('/quotas'),
          api<ActivityEvent[]>('/activity'),
        ])
        set((s) => ({
          clients,
          deployments,
          subscriptions,
          invoices,
          quotas,
          activity: activityFeed,
          // Charts/deltas/error-rate stay synthetic; recompute the two real KPIs from live data.
          dashboard: {
            ...s.dashboard,
            requests_24h: deployments.reduce((a, d) => a + Number(d.requests_24h || 0), 0),
            mrr: subscriptions.reduce(
              (a, x) => a + (x.billing_status !== 'overdue' ? Number(x.price_monthly || 0) : 0),
              0,
            ),
          },
          loaded: true,
        }))
      } catch (e) {
        fail(e instanceof Error ? e.message : 'Failed to load data')
      }
    },

    suspend: (id, input) => {
      set((s) => ({
        deployments: s.deployments.map((d) =>
          d.id === id
            ? {
                ...d,
                state: buildState('suspended', {
                  reason: input.reason,
                  message: input.message ?? d.state.message,
                  by: input.by ?? 'operator',
                }),
              }
            : d,
        ),
        activity: [
          activity('suspend', { deployment_id: id, reason: input.reason, by: input.by ?? 'operator' }),
          ...s.activity,
        ],
      }))
      persist(
        api<Deployment>(`/deployments/${id}/suspend`, {
          method: 'POST',
          body: { reason: input.reason, message: input.message ?? null },
        }),
        replaceDep,
      )
    },

    grace: (id, input) => {
      set((s) => ({
        deployments: s.deployments.map((d) =>
          d.id === id
            ? {
                ...d,
                state: buildState('grace', {
                  reason: input.reason,
                  message: input.message ?? d.state.message,
                  grace_until: input.grace_until,
                  by: input.by ?? 'operator',
                }),
              }
            : d,
        ),
        activity: [
          activity('grace', { deployment_id: id, reason: input.reason, by: input.by ?? 'operator' }),
          ...s.activity,
        ],
      }))
      persist(
        api<Deployment>(`/deployments/${id}/grace`, {
          method: 'POST',
          body: { reason: input.reason, message: input.message ?? null, grace_until: input.grace_until },
        }),
        replaceDep,
      )
    },

    resume: (id, by = 'operator') => {
      set((s) => ({
        deployments: s.deployments.map((d) =>
          d.id === id ? { ...d, state: buildState('active', { by }) } : d,
        ),
        activity: [activity('resume', { deployment_id: id, by }), ...s.activity],
      }))
      persist(api<Deployment>(`/deployments/${id}/resume`, { method: 'POST' }), replaceDep)
    },

    // The deploy mints + accepts the new secret (signed with the current one); we adopt the
    // returned deployment (new suffix). Not optimistic — secrets are server-generated.
    rotateSecret: async (id) => {
      try {
        const dep = await api<Deployment>(`/deployments/${id}/rotate-secret`, { method: 'POST' })
        if (dep && typeof dep === 'object' && 'id' in dep) replaceDep(dep)
        return dep?.control_secret_suffix ?? null
      } catch (e) {
        fail(e instanceof Error ? e.message : 'Secret rotation failed')
        return null
      }
    },

    editQuotaLimit: (quotaId, limit) => {
      set((s) => ({
        quotas: s.quotas.map((q) => {
          if (q.id !== quotaId) return q
          const over = limit != null && q.current_value > limit
          return {
            ...q,
            limit_value: limit,
            exceeded_at: over ? q.exceeded_at ?? new Date().toISOString() : null,
          }
        }),
      }))
      persist(api(`/quotas/${quotaId}`, { method: 'PATCH', body: { limit_value: limit } }))
    },

    setQuotaPolicy: (quotaId, policy) => {
      set((s) => ({
        quotas: s.quotas.map((q) => (q.id === quotaId ? { ...q, on_exceed: policy } : q)),
      }))
      persist(api(`/quotas/${quotaId}`, { method: 'PATCH', body: { on_exceed: policy } }))
    },

    markInvoicePaid: (invoiceId) => {
      const inv = get().invoices.find((i) => i.id === invoiceId)
      let resumableDeploymentId: string | undefined
      if (inv) {
        const dep = get().deployments.find((d) => d.id === inv.deployment_id)
        if (dep && dep.state.status === 'suspended' && dep.state.reason === 'payment_overdue') {
          resumableDeploymentId = dep.id
        }
      }
      set((s) => ({
        invoices: s.invoices.map((i) =>
          i.id === invoiceId ? { ...i, status: 'paid', enforcement: null } : i,
        ),
        activity: inv
          ? [
              activity('paid', {
                invoice_id: invoiceId,
                deployment_id: inv.deployment_id,
                by: 'operator',
              }),
              ...s.activity,
            ]
          : s.activity,
      }))
      persist(
        api<Invoice>(`/invoices/${invoiceId}`, {
          method: 'PATCH',
          body: { status: 'paid', enforcement: null },
        }),
        replaceInvoice,
      )
      return { resumableDeploymentId }
    },

    markInvoiceOverdue: (invoiceId) => {
      set((s) => ({
        invoices: s.invoices.map((i) => (i.id === invoiceId ? { ...i, status: 'overdue' } : i)),
      }))
      persist(
        api<Invoice>(`/invoices/${invoiceId}`, { method: 'PATCH', body: { status: 'overdue' } }),
        replaceInvoice,
      )
    },

    setInvoiceEnforcement: (invoiceId, enforcement) => {
      set((s) => ({
        invoices: s.invoices.map((i) => (i.id === invoiceId ? { ...i, enforcement } : i)),
      }))
      persist(
        api<Invoice>(`/invoices/${invoiceId}`, { method: 'PATCH', body: { enforcement } }),
        replaceInvoice,
      )
    },

    prependLog: (entry) => set((s) => ({ logs: [entry, ...s.logs].slice(0, 200) })),

    addClient: (client) => {
      const id = slug(client.name) || nextId('client')
      set((s) => ({
        clients: [...s.clients, { ...client, id, created_at: new Date().toISOString() }],
      }))
      persist(
        api<Client>('/clients', {
          method: 'POST',
          body: {
            id,
            name: client.name,
            company: client.company,
            email: client.email,
            phone: client.phone ?? null,
            notes: client.notes ?? null,
            status: client.status,
          },
        }),
        upsertClient,
      )
      return id
    },

    updateClient: (id, patch) => {
      set((s) => ({
        clients: s.clients.map((c) => (c.id === id ? { ...c, ...patch } : c)),
      }))
      persist(api<Client>(`/clients/${id}`, { method: 'PATCH', body: patch }), replaceClient)
    },

    createDeployment: (input) => {
      const id = slug(input.name)
      const now = new Date().toISOString()
      const dep: Deployment = {
        id,
        name: input.name,
        subdomain: `${id}.snakecloud.io`,
        client_id: input.client_id,
        package: input.package,
        version: input.version,
        state: buildState('active', { by: 'operator' }),
        requests_24h: 0,
        quota_health: 0,
        last_seen: now,
        base_url: `https://${id}.snakecloud.io/api/v1`,
        control_secret_suffix: Math.floor(Math.random() * 0xffff)
          .toString(16)
          .padStart(4, '0'),
        control_secret_rotated_at: now,
        subscription_id: null,
        created_at: now,
      }
      set((s) => ({ deployments: [...s.deployments, dep] }))
      persist(
        api<Deployment>('/deployments', {
          method: 'POST',
          body: {
            id,
            name: input.name,
            subdomain: dep.subdomain,
            client_id: input.client_id,
            package: input.package,
            version: input.version,
            base_url: dep.base_url,
            status: 'active',
          },
        }),
        upsertDep,
      )
      return id
    },

    deleteDeployment: (id) => {
      set((s) => ({
        deployments: s.deployments.filter((d) => d.id !== id),
        quotas: s.quotas.filter((q) => q.deployment_id !== id),
        subscriptions: s.subscriptions.filter((sub) => sub.deployment_id !== id),
      }))
      persist(api(`/deployments/${id}`, { method: 'DELETE' }))
    },

    updateSettings: (patch) => set((s) => ({ settings: { ...s.settings, ...patch } })),
  }
})

// ── Convenience selectors ──────────────────────────────────────────────────────
export const useDeployment = (id: string | undefined): Deployment | undefined =>
  useStore((s) => (id ? s.deployments.find((d) => d.id === id) : undefined))

export const useClient = (id: string | undefined): Client | undefined =>
  useStore((s) => (id ? s.clients.find((c) => c.id === id) : undefined))

export const useDeploymentQuotas = (id: string | undefined) => {
  // Select the stable array, derive with useMemo — returning a filtered array
  // straight from the selector would change identity every render and loop.
  const quotas = useStore((s) => s.quotas)
  return useMemo(() => quotas.filter((q) => q.deployment_id === id), [quotas, id])
}

export function clientName(state: StoreState, clientId: string): string {
  return state.clients.find((c) => c.id === clientId)?.name ?? clientId
}

export type { Subscription }
