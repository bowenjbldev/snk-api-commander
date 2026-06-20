import { useMemo } from 'react'
import { create } from 'zustand'
import type { Dataset } from './fixtures'
import { activity, buildState, nextId, type StateOpts } from './transitions'
import { api, packagesApi, settingsApi } from '../lib/api'
import type {
  ActivityEvent,
  Client,
  Deployment,
  Invoice,
  OnExceed,
  OperatorSettings,
  Plan,
  QuotaMetric,
  RegisteredPackage,
  Subscription,
} from './types'

/** Payload for the client-first provisioning flow (POST /provisioning). */
export interface ProvisionInput {
  plan_id: number
  client_id?: number // cliente existente (bigint id)
  // … o un cliente nuevo: la BD asigna el id, se manda el slug legible.
  client?: Partial<Omit<Client, 'id' | 'slug'>> & { slug: string; name: string; email: string }
  subdomain?: string
}

/** Payload for creating an invoice from the panel (POST /invoices). */
export interface InvoiceInput {
  client_id: number
  deployment_id: number
  amount: number
  issued_at: string
  due_at: string
  status?: Invoice['status']
}

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

  // ── deployment state machine (in-process: writes the package's commander_packages row) ──
  suspend: (id: number, input: SuspendInput) => void
  grace: (id: number, input: GraceInput) => void
  resume: (id: number, by?: StateOpts['by']) => void

  // ── quotas ──
  editQuotaLimit: (quotaId: number, limit: number | null) => void
  setQuotaPolicy: (quotaId: number, policy: OnExceed) => void

  // ── billing ──
  markInvoicePaid: (invoiceId: number) => { resumableDeploymentId?: number }
  markInvoiceOverdue: (invoiceId: number) => void
  setInvoiceEnforcement: (invoiceId: number, enforcement: Invoice['enforcement']) => void
  addInvoice: (input: InvoiceInput) => string
  updateInvoice: (id: number, patch: Partial<Pick<Invoice, 'amount' | 'issued_at' | 'due_at' | 'status'>>) => void
  deleteInvoice: (id: number) => void

  // ── CRUD ──
  addClient: (client: Omit<Client, 'id' | 'created_at' | 'slug'>) => string
  updateClient: (id: number, patch: Partial<Client>) => void
  deleteDeployment: (id: number) => void
  updateSettings: (patch: Partial<OperatorSettings>) => void

  // ── catalog (plans) ──
  addPlan: (plan: Omit<Plan, 'id'> & { slug: string }) => void
  updatePlan: (id: number, patch: Partial<Plan>) => void
  deletePlan: (id: number) => void

  // ── packages (registry metadata) ──
  updatePackage: (name: string, patch: Partial<Pick<RegisteredPackage, 'label' | 'description' | 'status'>>) => void

  // ── client-first provisioning (one-step, in-process) ──
  // Creates client (new/existing) + subscription + live instance (migrates + seeds + activates),
  // then refreshes the registry. No host step.
  provision: (input: ProvisionInput) => Promise<void>
}

export type StoreState = Dataset & StoreActions

// Production starts empty — no demo data. loadData() fills clients/deployments/etc. from the API;
// the registry is populated by provisioning (POST /provisioning) and the panel's CRUD. (Tests seed
// buildFixtures explicitly; see store.test.ts.)
const initial: Dataset = {
  clients: [],
  deployments: [],
  subscriptions: [],
  invoices: [],
  quotas: [],
  plans: [],
  packages: [],
  logs: [],
  activity: [],
  settings: {
    default_grace_hours: 72,
    operator_name: '',
    operator_email: '',
    notify_on_suspend: true,
    notify_on_overdue: true,
  },
  dashboard: {
    requests_24h: 0,
    requests_delta: 0,
    error_rate: 0,
    error_rate_delta: 0,
    mrr: 0,
    mrr_delta: 0,
  },
}

function slug(name: string): string {
  return name
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/(^-|-$)/g, '')
}

// Next sequential invoice number (INV-YYYY-NNNN) — el handle de negocio. El backend exige `number`
// único en POST /invoices y asigna el id (bigint); el panel lo deriva del max de los cargados.
function nextInvoiceNumber(invoices: Invoice[]): string {
  const year = new Date().getFullYear()
  const nums = invoices
    .map((i) => /^INV-\d{4}-(\d+)$/.exec(i.number)?.[1])
    .filter((x): x is string => x != null)
    .map(Number)
  const next = (nums.length ? Math.max(...nums) : 0) + 1
  return `INV-${year}-${String(next).padStart(4, '0')}`
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
    set((s) => ({ deployments: s.deployments.map((x) => (x.id === d.id ? d : x)) }))
  // Per-deployment action sequence: each optimistic state action bumps it, and a reconcile is
  // applied only if it is the response to the LATEST issued action for that deployment. This makes
  // two rapid out-of-order responses safe WITHOUT comparing the server's changed_at to the
  // optimistic client clock — that comparison dropped legitimate no-status-flip echoes (e.g.
  // re-suspending with a new reason) and mis-fired on benign client/server clock skew.
  const depActionSeq = new Map<number, number>()
  const reconcileDep = (id: number) => {
    const seq = (depActionSeq.get(id) ?? 0) + 1
    depActionSeq.set(id, seq)
    return (d: Deployment) => {
      if (depActionSeq.get(id) === seq) replaceDep(d)
    }
  }
  const replaceInvoice = (i: Invoice) =>
    set((s) => ({ invoices: s.invoices.map((x) => (x.id === i.id ? i : x)) }))
  // addInvoice: reconcilia por `number` (el id bigint lo asigna el servidor).
  const upsertInvoiceByNumber = (i: Invoice) =>
    set((s) => ({
      invoices: s.invoices.some((x) => x.number === i.number)
        ? s.invoices.map((x) => (x.number === i.number ? i : x))
        : [i, ...s.invoices],
    }))
  // Plan: reconcilia por slug (el id real bigint lo asigna el servidor).
  const upsertPlanBySlug = (p: Plan) =>
    set((s) => ({
      plans: s.plans.some((x) => x.slug === p.slug)
        ? s.plans.map((x) => (x.slug === p.slug ? p : x))
        : [...s.plans, p],
    }))
  const replaceClient = (c: Client) =>
    set((s) => ({ clients: s.clients.map((x) => (x.id === c.id ? c : x)) }))
  // Reconcilia por slug (no por id): en addClient el id real (bigint) lo asigna el servidor, así que
  // la fila optimista (id temporal) se empareja por su slug único.
  const upsertClient = (c: Client) =>
    set((s) => ({
      clients: s.clients.some((x) => x.slug === c.slug)
        ? s.clients.map((x) => (x.slug === c.slug ? c : x))
        : [...s.clients, c],
    }))

  return {
    ...initial,
    loaded: false,
    actionError: null,

    loadData: async () => {
      try {
        const [clients, deployments, subscriptions, invoices, quotas, plans, packages, activityFeed, settingsRow] = await Promise.all([
          api<Client[]>('/clients'),
          api<Deployment[]>('/deployments'),
          api<Subscription[]>('/subscriptions'),
          api<Invoice[]>('/invoices'),
          api<QuotaMetric[]>('/quotas'),
          api<Plan[]>('/plans'),
          packagesApi.list(),
          api<ActivityEvent[]>('/activity'),
          settingsApi.get(),
        ])
        set((s) => ({
          clients,
          deployments,
          subscriptions,
          invoices,
          quotas,
          plans,
          packages,
          activity: activityFeed,
          // Operator profile + preferences are now server-persisted (commander_settings singleton).
          settings: {
            default_grace_hours: Number(settingsRow.default_grace_hours) || s.settings.default_grace_hours,
            operator_name: settingsRow.operator_name ?? '',
            operator_email: settingsRow.operator_email ?? '',
            notify_on_suspend: settingsRow.notify_on_suspend ?? true,
            notify_on_overdue: settingsRow.notify_on_overdue ?? true,
          },
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
        reconcileDep(id),
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
        reconcileDep(id),
      )
    },

    resume: (id, by = 'operator') => {
      set((s) => ({
        deployments: s.deployments.map((d) =>
          d.id === id ? { ...d, state: buildState('active', { by }) } : d,
        ),
        activity: [activity('resume', { deployment_id: id, by }), ...s.activity],
      }))
      persist(api<Deployment>(`/deployments/${id}/resume`, { method: 'POST' }), reconcileDep(id))
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
      let resumableDeploymentId: number | undefined
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

    addClient: (client) => {
      const slugVal = slug(client.name) || nextId('client')
      const tempId = -Date.now() // id temporal (negativo) hasta que el server asigne el bigint real
      set((s) => ({
        clients: [...s.clients, { ...client, id: tempId, slug: slugVal, created_at: new Date().toISOString() }],
      }))
      persist(
        api<Client>('/clients', {
          method: 'POST',
          body: {
            slug: slugVal,
            name: client.name,
            company: client.company,
            email: client.email,
            phone: client.phone ?? null,
            notes: client.notes ?? null,
            status: client.status,
            billing_email: client.billing_email ?? null,
            tax_id: client.tax_id ?? null,
            address_line1: client.address_line1 ?? null,
            address_line2: client.address_line2 ?? null,
            city: client.city ?? null,
            postal_code: client.postal_code ?? null,
            country: client.country ?? null,
            payment_terms: client.payment_terms ?? null,
          },
        }),
        upsertClient,
      )
      return slugVal
    },

    updateClient: (id, patch) => {
      set((s) => ({
        clients: s.clients.map((c) => (c.id === id ? { ...c, ...patch } : c)),
      }))
      persist(api<Client>(`/clients/${id}`, { method: 'PATCH', body: patch }), replaceClient)
    },

    deleteDeployment: (id) => {
      set((s) => ({
        deployments: s.deployments.filter((d) => d.id !== id),
        quotas: s.quotas.filter((q) => q.deployment_id !== id),
        subscriptions: s.subscriptions.filter((sub) => sub.deployment_id !== id),
      }))
      persist(api(`/deployments/${id}`, { method: 'DELETE' }))
    },

    updateSettings: (patch) => {
      set((s) => ({ settings: { ...s.settings, ...patch } }))
      persist(settingsApi.update(patch))
    },

    addInvoice: (input) => {
      const number = nextInvoiceNumber(get().invoices)
      const tempId = -Date.now() // id temporal hasta que el server asigne el bigint real
      const invoice: Invoice = {
        id: tempId,
        number,
        client_id: input.client_id,
        deployment_id: input.deployment_id,
        amount: input.amount,
        issued_at: input.issued_at,
        due_at: input.due_at,
        status: input.status ?? 'pending',
        enforcement: null,
      }
      set((s) => ({ invoices: [invoice, ...s.invoices] }))
      persist(
        api<Invoice>('/invoices', {
          method: 'POST',
          body: {
            number,
            client_id: input.client_id,
            deployment_id: input.deployment_id,
            amount: input.amount,
            issued_at: input.issued_at,
            due_at: input.due_at,
            status: invoice.status,
          },
        }),
        upsertInvoiceByNumber,
      )
      return number
    },

    updateInvoice: (id, patch) => {
      set((s) => ({ invoices: s.invoices.map((i) => (i.id === id ? { ...i, ...patch } : i)) }))
      persist(api<Invoice>(`/invoices/${id}`, { method: 'PATCH', body: patch }), replaceInvoice)
    },

    deleteInvoice: (id) => {
      set((s) => ({ invoices: s.invoices.filter((i) => i.id !== id) }))
      persist(api(`/invoices/${id}`, { method: 'DELETE' }))
    },

    addPlan: (plan) => {
      const tempId = -Date.now() // id temporal hasta que el server asigne el bigint real
      set((s) => ({ plans: [...s.plans, { ...plan, id: tempId }] }))
      persist(api<Plan>('/plans', { method: 'POST', body: plan }), upsertPlanBySlug)
    },

    updatePlan: (id, patch) => {
      set((s) => ({ plans: s.plans.map((p) => (p.id === id ? { ...p, ...patch } : p)) }))
      persist(api<Plan>(`/plans/${id}`, { method: 'PATCH', body: patch }))
    },

    deletePlan: (id) => {
      set((s) => ({ plans: s.plans.filter((p) => p.id !== id) }))
      persist(api(`/plans/${id}`, { method: 'DELETE' }))
    },

    updatePackage: (name, patch) => {
      set((s) => ({ packages: s.packages.map((p) => (p.name === name ? { ...p, ...patch } : p)) }))
      persist(packagesApi.update(name, patch))
    },

    provision: async (input) => {
      // One-step, in-process: the server creates the client + subscription + live instance
      // (migrates + seeds + activates the package) atomically. Refresh the registry on success.
      await api<{ deployment: Deployment }>('/provisioning', { method: 'POST', body: input })
      await get().loadData()
    },
  }
})

// ── Convenience selectors ──────────────────────────────────────────────────────
export const useDeployment = (id: number | undefined): Deployment | undefined =>
  useStore((s) => (id != null ? s.deployments.find((d) => d.id === id) : undefined))

export const usePackage = (name: string | undefined): RegisteredPackage | undefined =>
  useStore((s) => (name ? s.packages.find((p) => p.name === name) : undefined))

export const useClient = (id: number | undefined): Client | undefined =>
  useStore((s) => (id != null ? s.clients.find((c) => c.id === id) : undefined))

export const useDeploymentQuotas = (id: number | undefined) => {
  // Select the stable array, derive with useMemo — returning a filtered array
  // straight from the selector would change identity every render and loop.
  const quotas = useStore((s) => s.quotas)
  return useMemo(() => quotas.filter((q) => q.deployment_id === id), [quotas, id])
}

export function clientName(state: StoreState, clientId: number): string {
  return state.clients.find((c) => c.id === clientId)?.name ?? String(clientId)
}

export type { Subscription }
