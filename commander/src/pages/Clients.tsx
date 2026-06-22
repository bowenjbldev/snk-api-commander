import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router'
import { Dropdown, Button, Tag } from '@carbon/react'
import { Add, UserMultiple, CheckmarkOutline, Box, Money, WarningAlt, Close } from '@carbon/icons-react'
import { useStore, useProvisionedPackages } from '../store/store'
import { useCan } from '../store/auth'
import { PageHeader } from '../components/PageHeader'
import { KpiCluster, KpiTile } from '../components/KpiCluster'
import { DataTableShell, type ShellColumn } from '../components/DataTableShell'
import { QuotaBar } from '../components/QuotaBar'
import { Sparkline } from '../components/Sparkline'
import { RelativeTime } from '../components/RelativeTime'
import { useFirstLoad } from '../hooks/useFirstLoad'
import { ClientCreatePanel } from '../components/ClientCreatePanel'
import { platformUsage } from '../lib/api'
import { formatMonthYear, formatAbsoluteUTC } from '../lib/time'
import { formatCurrencyShort, formatCompact } from '../lib/format'
import type { Client, UsageSnapshot } from '../store/types'

type StatusMix = { active: number; grace: number; suspended: number }

const STATUS_ITEMS = [
  { id: 'all', label: 'Status: All' },
  { id: 'active', label: 'Status: Active' },
  { id: 'inactive', label: 'Status: Inactive' },
]

const BILLING_ITEMS = [
  { id: 'all', label: 'Billing: All' },
  { id: 'risk', label: 'Billing: At risk' },
  { id: 'current', label: 'Billing: Current' },
]

const HEALTH_ITEMS = [
  { id: 'all', label: 'Health: All' },
  { id: 'suspended', label: 'Health: Any suspended' },
  { id: 'grace', label: 'Health: Any grace' },
  { id: 'healthy', label: 'Health: All healthy' },
]

// Country code → display label (extend as the roster grows; falls back to the raw ISO-2 code).
const COUNTRY_NAMES: Record<string, string> = {
  US: 'United States', GB: 'United Kingdom', ES: 'Spain', FR: 'France', DE: 'Germany',
  IT: 'Italy', PT: 'Portugal', NL: 'Netherlands', CA: 'Canada', MX: 'Mexico', BR: 'Brazil',
}

export function Clients() {
  const clients = useStore((s) => s.clients)
  const packages = useProvisionedPackages()
  const invoices = useStore((s) => s.invoices)
  const subscriptions = useStore((s) => s.subscriptions)
  const can = useCan()
  const navigate = useNavigate()

  const [statusFilter, setStatusFilter] = useState('all')
  const [billingFilter, setBillingFilter] = useState('all')
  const [healthFilter, setHealthFilter] = useState('all')
  const [countryFilter, setCountryFilter] = useState('all')
  const [createOpen, setCreateOpen] = useState(false)

  // Per-client rollups — all derived from data already in the store (no new endpoints/tables).
  // Package status mix (active/grace/suspended counts) per client — feeds the Health filter.
  const statusMixByClient = useMemo(() => {
    const m = new Map<number, StatusMix>()
    for (const p of packages) {
      if (p.client_id == null) continue
      const cur = m.get(p.client_id) ?? { active: 0, grace: 0, suspended: 0 }
      cur[p.status] += 1
      m.set(p.client_id, cur)
    }
    return m
  }, [packages])

  // Worst quota_health (0..>1 ratio) across a client's packages — surfaced as a QuotaBar.
  const quotaHealthByClient = useMemo(() => {
    const m = new Map<number, number>()
    for (const p of packages) {
      if (p.client_id == null) continue
      m.set(p.client_id, Math.max(m.get(p.client_id) ?? 0, Number(p.quota_health || 0)))
    }
    return m
  }, [packages])

  // Most recent last_seen across a client's packages (ISO compares lexically) — liveness signal.
  const lastSeenByClient = useMemo(() => {
    const m = new Map<number, string>()
    for (const p of packages) {
      if (p.client_id == null || p.last_seen == null) continue
      const cur = m.get(p.client_id)
      if (cur == null || p.last_seen > cur) m.set(p.client_id, p.last_seen)
    }
    return m
  }, [packages])

  // Per-client 48h request sparkline series — aggregated from the platform usage snapshot feed
  // (same async one-shot the Dashboard uses; kept in local state, not the store). Each snapshot's
  // package_id is mapped to its owning client and accumulated into a time-ordered bucket array.
  const [spark, setSpark] = useState<Map<number, number[]>>(new Map())
  useEffect(() => {
    let cancelled = false
    const pkgClient = new Map(packages.map((p) => [p.id, p.client_id]))
    platformUsage({ period: 'hour', hours: 48 })
      .then((res) => {
        if (cancelled) return
        const snaps: UsageSnapshot[] = res.data ?? []
        const buckets = [...new Set(snaps.map((s) => s.period_start))].sort()
        const idx = new Map(buckets.map((b, i) => [b, i]))
        const next = new Map<number, number[]>()
        for (const s of snaps) {
          const clientId = pkgClient.get(s.package_id)
          if (clientId == null) continue
          const arr = next.get(clientId) ?? new Array(buckets.length).fill(0)
          arr[idx.get(s.period_start)!] += Number(s.request_count || 0)
          next.set(clientId, arr)
        }
        setSpark(next)
      })
      .catch(() => {
        if (!cancelled) setSpark(new Map())
      })
    return () => {
      cancelled = true
    }
  }, [packages])

  // Live 24h traffic per client = sum of their provisioned packages' requests_24h.
  const reqByClient = useMemo(() => {
    const m = new Map<number, number>()
    for (const p of packages) {
      if (p.client_id == null) continue
      m.set(p.client_id, (m.get(p.client_id) ?? 0) + Number(p.requests_24h || 0))
    }
    return m
  }, [packages])

  // MRR per client = current subscription terms (ended_at == null), excluding overdue —
  // same rule the Dashboard MRR uses (store.ts loadData).
  const mrrByClient = useMemo(() => {
    const m = new Map<number, number>()
    for (const s of subscriptions) {
      if (s.ended_at != null || s.billing_status === 'overdue') continue
      m.set(s.client_id, (m.get(s.client_id) ?? 0) + Number(s.price_monthly || 0))
    }
    return m
  }, [subscriptions])

  // Clients carrying at least one overdue invoice — surfaced as a billing-risk marker.
  const overdueClients = useMemo(() => {
    const set = new Set<number>()
    for (const i of invoices) if (i.status === 'overdue') set.add(i.client_id)
    return set
  }, [invoices])

  // Distinct countries present in the roster — the Country filter only appears when there's
  // more than one to choose between.
  const countryItems = useMemo(() => {
    const codes = [...new Set(clients.map((c) => c.country).filter((x): x is string => !!x))].sort()
    return [
      { id: 'all', label: 'Country: All' },
      ...codes.map((code) => ({ id: code, label: `Country: ${COUNTRY_NAMES[code] ?? code}` })),
    ]
  }, [clients])
  const showCountryFilter = countryItems.length > 2

  const matchesHealth = (c: Client): boolean => {
    if (healthFilter === 'all') return true
    const mix = statusMixByClient.get(c.id)
    if (!mix) return false
    if (healthFilter === 'suspended') return mix.suspended > 0
    if (healthFilter === 'grace') return mix.grace > 0
    return mix.suspended === 0 && mix.grace === 0 && mix.active > 0 // healthy
  }

  const filtered = useMemo(
    () =>
      clients.filter((c) => {
        if (statusFilter !== 'all' && c.status !== statusFilter) return false
        if (billingFilter === 'risk' && !overdueClients.has(c.id)) return false
        if (billingFilter === 'current' && overdueClients.has(c.id)) return false
        if (showCountryFilter && countryFilter !== 'all' && c.country !== countryFilter) return false
        if (!matchesHealth(c)) return false
        return true
      }),
    // matchesHealth closes over healthFilter + statusMixByClient; list them so it re-runs.
    [clients, statusFilter, billingFilter, countryFilter, showCountryFilter, healthFilter, overdueClients, statusMixByClient], // eslint-disable-line react-hooks/exhaustive-deps
  )

  const anyFilter =
    statusFilter !== 'all' || billingFilter !== 'all' || healthFilter !== 'all' ||
    countryFilter !== 'all'
  const clearFilters = () => {
    setStatusFilter('all')
    setBillingFilter('all')
    setHealthFilter('all')
    setCountryFilter('all')
  }

  const active = clients.filter((c) => c.status === 'active').length
  const totalMrr = useMemo(() => [...mrrByClient.values()].reduce((a, v) => a + v, 0), [mrrByClient])

  const columns: ShellColumn<Client>[] = [
    {
      key: 'name',
      header: 'Client',
      sortable: true,
      sortValue: (c) => c.name,
      width: 280,
      render: (c) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <span
            className="scns-status-dot"
            title={c.status === 'active' ? 'Active' : 'Inactive'}
            aria-label={c.status === 'active' ? 'Active' : 'Inactive'}
            style={{ background: c.status === 'active' ? 'var(--scns-success)' : 'var(--cds-text-placeholder)' }}
          />
          <button
            type="button"
            className="scns-linkbtn"
            onClick={() => navigate(`/clients/${c.id}`)}
            style={{ fontWeight: 600 }}
          >
            {c.name}
          </button>
        </div>
      ),
    },
    {
      key: 'mrr',
      header: 'MRR',
      sortable: true,
      sortValue: (c) => mrrByClient.get(c.id) ?? 0,
      width: 120,
      render: (c) => {
        const v = mrrByClient.get(c.id) ?? 0
        return v > 0 ? (
          <Tag type="cool-gray" size="sm">{formatCurrencyShort(v)}/mo</Tag>
        ) : (
          <span className="scns-num scns-helper">—</span>
        )
      },
    },
    {
      key: 'traffic',
      header: 'Traffic · 24h',
      sortable: true,
      sortValue: (c) => reqByClient.get(c.id) ?? 0,
      width: 160,
      render: (c) => (
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: 10 }}>
          <span className="scns-num" style={{ fontSize: 12 }}>
            {formatCompact(reqByClient.get(c.id) ?? 0)}
          </span>
          <Sparkline data={spark.get(c.id) ?? []} />
        </div>
      ),
    },
    {
      key: 'health',
      header: 'Quota health',
      sortable: true,
      sortValue: (c) => quotaHealthByClient.get(c.id) ?? -1,
      width: 150,
      render: (c) => {
        const h = quotaHealthByClient.get(c.id)
        return h == null ? (
          <span className="scns-num scns-helper" style={{ fontSize: 12 }}>—</span>
        ) : (
          <QuotaBar current={h} limit={1} showLabel />
        )
      },
    },
    {
      key: 'last_seen',
      header: 'Last seen',
      sortable: true,
      sortValue: (c) => lastSeenByClient.get(c.id) ?? '',
      width: 130,
      render: (c) => {
        const ls = lastSeenByClient.get(c.id)
        return ls ? (
          <RelativeTime iso={ls} />
        ) : (
          <span className="scns-reltime">never</span>
        )
      },
    },
    {
      key: 'email',
      header: 'Email',
      sortable: true,
      sortValue: (c) => c.email,
      render: (c) => <span className="scns-mono" style={{ fontSize: 12 }}>{c.email}</span>,
    },
    {
      key: 'created',
      header: 'Created',
      sortable: true,
      sortValue: (c) => c.created_at,
      width: 110,
      render: (c) => (
        <span className="scns-reltime" title={formatAbsoluteUTC(c.created_at)}>
          {formatMonthYear(c.created_at)}
        </span>
      ),
    },
  ]

  return (
    <div className="scns-page">
      <PageHeader
        title="Clients"
        subtitle="Customer accounts, billing and package usage"
        primaryAction={
          can('create clients')
            ? { label: 'Create client', icon: Add, onClick: () => setCreateOpen(true) }
            : undefined
        }
      />

      <div style={{ marginBottom: 32 }}>
      <KpiCluster columns={5}>
        <KpiTile
          label="Clients"
          value={clients.length}
          helper={`${active} active · ${clients.length - active} inactive`}
          icon={<UserMultiple size={20} />}
        />
        <KpiTile
          label="Active"
          value={active}
          helper={`of ${clients.length}`}
          icon={<CheckmarkOutline size={20} />}
          iconClass="scns-val-green"
        />
        <KpiTile
          label="Packages"
          value={packages.length}
          helper="provisioned"
          icon={<Box size={20} />}
        />
        <KpiTile label="MRR" value={formatCurrencyShort(totalMrr)} helper="current terms" icon={<Money size={20} />} />
        <KpiTile
          label="At risk"
          value={overdueClients.size}
          valueClass={overdueClients.size > 0 ? 'scns-val-red' : undefined}
          helper="overdue invoices"
          icon={<WarningAlt size={20} />}
          iconClass={overdueClients.size > 0 ? 'scns-val-red' : undefined}
        />
      </KpiCluster>
      </div>

      <DataTableShell
        rows={filtered}
        columns={columns}
        getId={(c) => c.id}
        searchText={(c) => `${c.name} ${c.company ?? ''} ${c.email}`}
        searchPlaceholder="Search clients"
        initialSort={{ key: 'name', dir: 'ASC' }}
        itemLabel="clients"
        loading={useFirstLoad()}
        onRowClick={(c) => navigate(`/clients/${c.id}`)}
        filters={
          <>
            <Dropdown
              id="filter-client-status"
              type="inline"
              size="lg"
              label="Status: All"
              titleText="Filter by status"
              hideLabel
              items={STATUS_ITEMS}
              itemToString={(i) => i?.label ?? ''}
              selectedItem={STATUS_ITEMS.find((i) => i.id === statusFilter)}
              onChange={({ selectedItem }) => setStatusFilter(selectedItem?.id ?? 'all')}
            />
            <Dropdown
              id="filter-client-billing"
              type="inline"
              size="lg"
              label="Billing: All"
              titleText="Filter by billing"
              hideLabel
              items={BILLING_ITEMS}
              itemToString={(i) => i?.label ?? ''}
              selectedItem={BILLING_ITEMS.find((i) => i.id === billingFilter)}
              onChange={({ selectedItem }) => setBillingFilter(selectedItem?.id ?? 'all')}
            />
            <Dropdown
              id="filter-client-health"
              type="inline"
              size="lg"
              label="Health: All"
              titleText="Filter by package health"
              hideLabel
              items={HEALTH_ITEMS}
              itemToString={(i) => i?.label ?? ''}
              selectedItem={HEALTH_ITEMS.find((i) => i.id === healthFilter)}
              onChange={({ selectedItem }) => setHealthFilter(selectedItem?.id ?? 'all')}
            />
            {showCountryFilter && (
              <Dropdown
                id="filter-client-country"
                type="inline"
                size="lg"
                label="Country: All"
                titleText="Filter by country"
                hideLabel
                items={countryItems}
                itemToString={(i) => i?.label ?? ''}
                selectedItem={countryItems.find((i) => i.id === countryFilter)}
                onChange={({ selectedItem }) => setCountryFilter(selectedItem?.id ?? 'all')}
              />
            )}
            {anyFilter && (
              <Button kind="ghost" size="md" renderIcon={Close} onClick={clearFilters}>
                Clear filters
              </Button>
            )}
          </>
        }
      />

      {createOpen && (
        <ClientCreatePanel open onClose={() => setCreateOpen(false)} />
      )}
    </div>
  )
}
