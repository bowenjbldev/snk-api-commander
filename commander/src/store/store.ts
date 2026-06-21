import { useMemo } from 'react'
import { create } from 'zustand'
import type { Dataset } from './fixtures'
import { activity, applyPackageState, nextId, type StateOpts } from './transitions'
import { api, packagesApi, settingsApi } from '../lib/api'
import type {
  ActivityEvent,
  Client,
  Invoice,
  OnExceed,
  OperatorSettings,
  Package,
  Plan,
  QuotaMetric,
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
  package_id: number
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

  // ── package state machine (in-process: writes the package's commander_packages row), por `name` ──
  suspend: (name: string, input: SuspendInput) => void
  grace: (name: string, input: GraceInput) => void
  resume: (name: string, by?: StateOpts['by']) => void

  // ── quotas ──
  editQuotaLimit: (quotaId: number, limit: number | null) => void
  setQuotaPolicy: (quotaId: number, policy: OnExceed) => void

  // ── billing ──
  markInvoicePaid: (invoiceId: number) => { resumablePackageName?: string }
  markInvoiceOverdue: (invoiceId: number) => void
  setInvoiceEnforcement: (invoiceId: number, enforcement: Invoice['enforcement']) => void
  addInvoice: (input: InvoiceInput) => string
  updateInvoice: (id: number, patch: Partial<Pick<Invoice, 'amount' | 'issued_at' | 'due_at' | 'status'>>) => void
  deleteInvoice: (id: number) => void

  // ── CRUD ──
  addClient: (client: Omit<Client, 'id' | 'created_at' | 'slug'>) => string
  updateClient: (id: number, patch: Partial<Client>) => void
  // De-provision (soft): nulls the package's operational columns (DROP DATABASE + clear metering) but
  // keeps the catalog row + billing history. The package becomes catalog-only (provisioned_at = null).
  deprovision: (name: string) => void
  updateSettings: (patch: Partial<OperatorSettings>) => void

  // ── catalog (plans) ──
  addPlan: (plan: Omit<Plan, 'id'> & { slug: string }) => void
  updatePlan: (id: number, patch: Partial<Plan>) => void
  deletePlan: (id: number) => void

  // ── package catalog metadata ──
  updatePackage: (name: string, patch: Partial<Pick<Package, 'label' | 'description' | 'listing_status'>>) => void

  // ── client-first provisioning (one-step, in-process) ──
  // Creates client (new/existing) + subscription + fills the package's live columns (migrates + seeds +
  // activates), then refreshes the registry. No host step.
  provision: (input: ProvisionInput) => Promise<void>
}

export type StoreState = Dataset & StoreActions

// The live provisioned packages (commander_packages rows with a live instance). Catalog-only rows
// (provisioned_at == null) are filtered out — they're business package types not yet deployed.
export function provisionedPackages(packages: Package[]): Package[] {
  return packages.filter((p) => p.provisioned_at != null)
}

// Production starts empty — no demo data. loadData() fills clients/packages/etc. from the API; the
// registry is synced from disk + populated by provisioning and the panel's CRUD. (Tests seed
// buildFixtures explicitly; see store.test.ts.)
const initial: Dataset = {
  clients: [],
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

  // The numeric package_id (= the unified row id) for a package name (for activity rows / relations).
  const pkgId = (name: string): number | undefined => get().packages.find((p) => p.name === name)?.id

  // Reconcile the server's authoritative package row into the registry, merging over the existing
  // entry so enrichment (on_disk, plan_count) survives a state-action response that omits it.
  const replacePackageRow = (row: Package) =>
    set((s) => ({ packages: s.packages.map((p) => (p.name === row.name ? { ...p, ...row } : p)) }))
  // Per-package action sequence: each optimistic state action bumps it, and a reconcile is applied
  // only if it is the response to the LATEST issued action for that package. Makes two rapid
  // out-of-order responses safe without comparing the server's changed_at to the client clock.
  const pkgActionSeq = new Map<string, number>()
  const reconcilePackage = (name: string) => {
    const seq = (pkgActionSeq.get(name) ?? 0) + 1
    pkgActionSeq.set(name, seq)
    return (row: Package) => {
      if (pkgActionSeq.get(name) === seq) replacePackageRow(row)
    }
  }

  // Optimistic state transition on the package row (by name), only if it has a live instance.
  const applyLocalState = (name: string, status: Package['status'], opts: StateOpts) =>
    set((s) => ({
      packages: s.packages.map((p) =>
        p.name === name && p.provisioned_at != null ? applyPackageState(p, status, opts) : p,
      ),
    }))

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
        const [clients, packages, subscriptions, invoices, quotas, plans, activityFeed, settingsRow] = await Promise.all([
          api<Client[]>('/clients'),
          packagesApi.list(),
          api<Subscription[]>('/subscriptions'),
          api<Invoice[]>('/invoices'),
          api<QuotaMetric[]>('/quotas'),
          api<Plan[]>('/plans'),
          api<ActivityEvent[]>('/activity'),
          settingsApi.get(),
        ])
        const provisioned = provisionedPackages(packages)
        set((s) => ({
          clients,
          packages,
          subscriptions,
          invoices,
          quotas,
          plans,
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
            requests_24h: provisioned.reduce((a, p) => a + Number(p.requests_24h || 0), 0),
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

    suspend: (name, input) => {
      applyLocalState(name, 'suspended', {
        reason: input.reason,
        message: input.message,
        by: input.by ?? 'operator',
      })
      set((s) => ({
        activity: [
          activity('suspend', { package_id: pkgId(name), reason: input.reason, by: input.by ?? 'operator' }),
          ...s.activity,
        ],
      }))
      persist(
        packagesApi.suspend(name, { reason: input.reason, message: input.message ?? null }),
        reconcilePackage(name),
      )
    },

    grace: (name, input) => {
      applyLocalState(name, 'grace', {
        reason: input.reason,
        message: input.message,
        grace_until: input.grace_until,
        by: input.by ?? 'operator',
      })
      set((s) => ({
        activity: [
          activity('grace', { package_id: pkgId(name), reason: input.reason, by: input.by ?? 'operator' }),
          ...s.activity,
        ],
      }))
      persist(
        packagesApi.grace(name, {
          reason: input.reason,
          message: input.message ?? null,
          grace_until: input.grace_until,
        }),
        reconcilePackage(name),
      )
    },

    resume: (name, by = 'operator') => {
      applyLocalState(name, 'active', { by })
      set((s) => ({ activity: [activity('resume', { package_id: pkgId(name), by }), ...s.activity] }))
      persist(packagesApi.resume(name), reconcilePackage(name))
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
      let resumablePackageName: string | undefined
      if (inv) {
        const reg = get().packages.find((p) => p.id === inv.package_id && p.provisioned_at != null)
        if (reg && reg.status === 'suspended' && reg.reason === 'payment_overdue') {
          resumablePackageName = reg.name
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
                package_id: inv.package_id,
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
      return { resumablePackageName }
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

    deprovision: (name) => {
      const id = pkgId(name)
      set((s) => ({
        // Soft: la fila persiste como solo-catalogo (provisioned_at null), conservando label/description.
        packages: s.packages.map((p) =>
          p.name === name
            ? {
                ...p,
                provisioned_at: null,
                subdomain: null,
                database: null,
                client_id: null,
                subscription_id: null,
                status: 'active',
                reason: null,
                message: null,
                grace_until: null,
                requests_24h: 0,
                quota_health: 0,
                last_seen: null,
                client: null,
                subscription: null,
                quotas: [],
              }
            : p,
        ),
        // El metering/cuotas de la instancia muere; el billing (subscriptions/invoices) se conserva.
        quotas: id != null ? s.quotas.filter((q) => q.package_id !== id) : s.quotas,
      }))
      persist(packagesApi.deprovision(name))
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
        package_id: input.package_id,
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
            package_id: input.package_id,
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
      // One-step, in-process: the server creates the client + subscription + fills the package's live
      // columns (migrates + seeds + activates the package) atomically. Refresh the registry on success.
      await api<{ package: Package }>('/provisioning', { method: 'POST', body: input })
      await get().loadData()
    },
  }
})

// ── Convenience selectors ──────────────────────────────────────────────────────
export const usePackage = (name: string | undefined): Package | undefined =>
  useStore((s) => (name ? s.packages.find((p) => p.name === name) : undefined))

// The package row only if it has a live instance (provisioned), else undefined.
export const useProvisionedPackage = (name: string | undefined): Package | undefined =>
  useStore((s) =>
    name ? s.packages.find((p) => p.name === name && p.provisioned_at != null) : undefined,
  )

// All live provisioned package rows, as a stable array.
export function useProvisionedPackages(): Package[] {
  const packages = useStore((s) => s.packages)
  return useMemo(() => provisionedPackages(packages), [packages])
}

export const useClient = (id: number | undefined): Client | undefined =>
  useStore((s) => (id != null ? s.clients.find((c) => c.id === id) : undefined))

export const usePackageQuotas = (packageId: number | undefined) => {
  // Select the stable array, derive with useMemo — returning a filtered array straight from the
  // selector would change identity every render and loop.
  const quotas = useStore((s) => s.quotas)
  return useMemo(() => quotas.filter((q) => q.package_id === packageId), [quotas, packageId])
}

export function clientName(state: StoreState, clientId: number): string {
  return state.clients.find((c) => c.id === clientId)?.name ?? String(clientId)
}

export type { Subscription }
