import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { Dropdown, Button, Tag } from '@carbon/react'
import { Box, CheckmarkOutline, Time, PauseOutline, Activity, Money, Close } from '@carbon/icons-react'
import { useStore } from '../store/store'
import { PageHeader } from '../components/PageHeader'
import { KpiCluster, KpiTile } from '../components/KpiCluster'
import { DataTableShell, type ShellColumn } from '../components/DataTableShell'
import { QuotaBar } from '../components/QuotaBar'
import { Sparkline } from '../components/Sparkline'
import { RelativeTime } from '../components/RelativeTime'
import { useFirstLoad } from '../hooks/useFirstLoad'
import { platformUsage } from '../lib/api'
import { formatCountdownShort, msUntil, formatMonthYear, formatAbsoluteUTC } from '../lib/time'
import { formatCompact, formatCurrencyShort } from '../lib/format'
import type { Package, UsageSnapshot } from '../store/types'

const PROV_ITEMS = [
  { id: 'all', label: 'Provisioned: All' },
  { id: 'live', label: 'Provisioned: Live' },
  { id: 'catalog', label: 'Provisioned: Catalog-only' },
]
const STATUS_ITEMS = [
  { id: 'all', label: 'Status: All' },
  { id: 'operational', label: 'Status: Operational' },
  { id: 'grace', label: 'Status: Grace' },
  { id: 'suspended', label: 'Status: Suspended' },
]
const LISTING_ITEMS = [
  { id: 'all', label: 'Listing: All' },
  { id: 'active', label: 'Listing: Active' },
  { id: 'inactive', label: 'Listing: Inactive' },
]

// Status dot in the Package cell (replaces the Status column): runtime state for live instances,
// neutral grey for catalog-only rows.
function pkgDotColor(p: Package): string {
  if (p.provisioned_at == null) return 'var(--cds-text-placeholder)'
  if (p.status === 'active') return 'var(--scns-success)'
  if (p.status === 'grace') return 'var(--scns-warning)'
  return 'var(--scns-error)'
}
function pkgStatusLabel(p: Package): string {
  if (p.provisioned_at == null) return 'Catalog (not provisioned)'
  if (p.status === 'active') return 'Operational'
  if (p.status === 'grace') {
    const left = p.grace_until ? formatCountdownShort(msUntil(p.grace_until, Date.now())) : null
    return left ? `Grace · ${left} left` : 'Grace'
  }
  return 'Suspended'
}

// Hub package-céntrico: lista los packages de negocio (catálogo + instancias vivas). Cada fila entra
// a /packages/:id con sus tabs. Las columnas operativas (client/plan/tráfico/cuota) solo aplican a las
// provisionadas; las de catálogo muestran "—".
export function Packages() {
  const packages = useStore((s) => s.packages)
  const clients = useStore((s) => s.clients)
  const subscriptions = useStore((s) => s.subscriptions)
  const navigate = useNavigate()

  const [provFilter, setProvFilter] = useState('all')
  const [statusFilter, setStatusFilter] = useState('all')
  const [listingFilter, setListingFilter] = useState('all')

  const provisioned = useMemo(() => packages.filter((p) => p.provisioned_at != null), [packages])
  const clientName = useMemo(() => new Map(clients.map((c) => [c.id, c.name])), [clients])
  const subByPackage = useMemo(
    () => new Map(subscriptions.filter((s) => s.ended_at == null).map((s) => [s.package_id, s])),
    [subscriptions],
  )

  const counts = useMemo(() => {
    let active = 0, grace = 0, suspended = 0
    for (const p of provisioned) {
      if (p.status === 'active') active++
      else if (p.status === 'grace') grace++
      else suspended++
    }
    return { active, grace, suspended }
  }, [provisioned])
  const requests24h = useMemo(() => provisioned.reduce((a, p) => a + Number(p.requests_24h || 0), 0), [provisioned])
  const mrr = useMemo(
    () =>
      subscriptions
        .filter((s) => s.ended_at == null && s.billing_status !== 'overdue')
        .reduce((a, s) => a + Number(s.price_monthly || 0), 0),
    [subscriptions],
  )

  // Per-package 48h request sparkline series — straight from the platform usage feed (keyed by
  // package_id, no aggregation needed). Local state, like the Dashboard / Clients.
  const [spark, setSpark] = useState<Map<number, number[]>>(new Map())
  useEffect(() => {
    let cancelled = false
    platformUsage({ period: 'hour', hours: 48 })
      .then((res) => {
        if (cancelled) return
        const snaps: UsageSnapshot[] = res.data ?? []
        const buckets = [...new Set(snaps.map((s) => s.period_start))].sort()
        const idx = new Map(buckets.map((b, i) => [b, i]))
        const next = new Map<number, number[]>()
        for (const s of snaps) {
          const arr = next.get(s.package_id) ?? new Array(buckets.length).fill(0)
          arr[idx.get(s.period_start)!] += Number(s.request_count || 0)
          next.set(s.package_id, arr)
        }
        setSpark(next)
      })
      .catch(() => {
        if (!cancelled) setSpark(new Map())
      })
    return () => {
      cancelled = true
    }
  }, [])

  const filtered = useMemo(
    () =>
      packages.filter((p) => {
        const live = p.provisioned_at != null
        if (provFilter === 'live' && !live) return false
        if (provFilter === 'catalog' && live) return false
        if (statusFilter !== 'all') {
          if (!live) return false
          const want = statusFilter === 'operational' ? 'active' : statusFilter
          if (p.status !== want) return false
        }
        if (listingFilter !== 'all' && p.listing_status !== listingFilter) return false
        return true
      }),
    [packages, provFilter, statusFilter, listingFilter],
  )

  const anyFilter = provFilter !== 'all' || statusFilter !== 'all' || listingFilter !== 'all'
  const clearFilters = () => {
    setProvFilter('all')
    setStatusFilter('all')
    setListingFilter('all')
  }

  const columns: ShellColumn<Package>[] = [
    {
      key: 'name',
      header: 'Package',
      sortable: true,
      sortValue: (p) => p.label ?? p.name,
      width: 240,
      render: (p) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <span
            className="scns-status-dot"
            title={pkgStatusLabel(p)}
            aria-label={pkgStatusLabel(p)}
            style={{ background: pkgDotColor(p) }}
          />
          <Link to={`/packages/${p.id}`} className="scns-link" style={{ fontWeight: 600 }}>
            {p.label ?? p.name}
          </Link>
        </div>
      ),
    },
    {
      key: 'plan',
      header: 'Plan',
      sortable: true,
      sortValue: (p) => subByPackage.get(p.id)?.price_monthly ?? -1,
      width: 160,
      render: (p) => {
        const sub = subByPackage.get(p.id)
        return sub ? (
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <Tag type="cool-gray" size="sm">{sub.plan}</Tag>
            <span className="scns-num scns-helper" style={{ fontSize: 12 }}>
              {formatCurrencyShort(sub.price_monthly)}/mo
            </span>
          </div>
        ) : (
          <span className="scns-helper">—</span>
        )
      },
    },
    {
      key: 'traffic',
      header: 'Traffic · 24h',
      sortable: true,
      sortValue: (p) => Number(p.requests_24h || 0),
      width: 160,
      render: (p) =>
        p.provisioned_at != null ? (
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: 10 }}>
            <span className="scns-num" style={{ fontSize: 12 }}>{formatCompact(Number(p.requests_24h || 0))}</span>
            <Sparkline data={spark.get(p.id) ?? []} />
          </div>
        ) : (
          <span className="scns-num scns-helper">—</span>
        ),
    },
    {
      key: 'health',
      header: 'Quota health',
      sortable: true,
      sortValue: (p) => (p.provisioned_at != null ? Number(p.quota_health || 0) : -1),
      width: 150,
      render: (p) =>
        p.provisioned_at != null ? (
          <QuotaBar current={Number(p.quota_health || 0)} limit={1} showLabel />
        ) : (
          <span className="scns-num scns-helper" style={{ fontSize: 12 }}>—</span>
        ),
    },
    {
      key: 'last_seen',
      header: 'Last seen',
      sortable: true,
      sortValue: (p) => p.last_seen ?? '',
      width: 130,
      render: (p) =>
        p.provisioned_at != null ? (
          p.last_seen ? <RelativeTime iso={p.last_seen} /> : <span className="scns-reltime">never</span>
        ) : (
          <span className="scns-helper">—</span>
        ),
    },
    {
      key: 'created',
      header: 'Created',
      sortable: true,
      sortValue: (p) => p.created_at,
      width: 110,
      render: (p) => (
        <span className="scns-reltime" title={formatAbsoluteUTC(p.created_at)}>
          {formatMonthYear(p.created_at)}
        </span>
      ),
    },
  ]

  return (
    <div className="scns-page">
      <PageHeader
        title="Packages"
        subtitle="Business packages, plans and live deployments"
      />

      <div style={{ marginBottom: 32 }}>
        <KpiCluster columns={6}>
          <KpiTile label="Packages" value={packages.length} helper={`${provisioned.length} provisioned`} icon={<Box size={20} />} />
          <KpiTile label="Active" value={counts.active} helper="operational" icon={<CheckmarkOutline size={20} />} iconClass="scns-val-green" />
          <KpiTile label="In grace" value={counts.grace} valueClass={counts.grace > 0 ? 'scns-val-amber' : undefined} helper="awaiting payment" icon={<Time size={20} />} iconClass={counts.grace > 0 ? 'scns-val-amber' : undefined} />
          <KpiTile label="Suspended" value={counts.suspended} valueClass={counts.suspended > 0 ? 'scns-val-red' : undefined} helper="serving HTTP 402" icon={<PauseOutline size={20} />} iconClass={counts.suspended > 0 ? 'scns-val-red' : undefined} />
          <KpiTile label="Requests · 24h" value={formatCompact(requests24h)} helper="across packages" icon={<Activity size={20} />} />
          <KpiTile label="MRR" value={formatCurrencyShort(mrr)} helper="current terms" icon={<Money size={20} />} />
        </KpiCluster>
      </div>

      <DataTableShell
        rows={filtered}
        columns={columns}
        getId={(p) => p.name}
        searchText={(p) => `${p.name} ${p.label ?? ''} ${p.client_id != null ? clientName.get(p.client_id) ?? '' : ''}`}
        searchPlaceholder="Search packages"
        initialSort={{ key: 'name', dir: 'ASC' }}
        itemLabel="packages"
        loading={useFirstLoad()}
        onRowClick={(p) => navigate(`/packages/${p.id}`)}
        filters={
          <>
            <Dropdown
              id="filter-pkg-prov"
              type="inline"
              size="lg"
              label="Provisioned: All"
              titleText="Filter by provisioning"
              hideLabel
              items={PROV_ITEMS}
              itemToString={(i) => i?.label ?? ''}
              selectedItem={PROV_ITEMS.find((i) => i.id === provFilter)}
              onChange={({ selectedItem }) => setProvFilter(selectedItem?.id ?? 'all')}
            />
            <Dropdown
              id="filter-pkg-status"
              type="inline"
              size="lg"
              label="Status: All"
              titleText="Filter by runtime status"
              hideLabel
              items={STATUS_ITEMS}
              itemToString={(i) => i?.label ?? ''}
              selectedItem={STATUS_ITEMS.find((i) => i.id === statusFilter)}
              onChange={({ selectedItem }) => setStatusFilter(selectedItem?.id ?? 'all')}
            />
            <Dropdown
              id="filter-pkg-listing"
              type="inline"
              size="lg"
              label="Listing: All"
              titleText="Filter by listing status"
              hideLabel
              items={LISTING_ITEMS}
              itemToString={(i) => i?.label ?? ''}
              selectedItem={LISTING_ITEMS.find((i) => i.id === listingFilter)}
              onChange={({ selectedItem }) => setListingFilter(selectedItem?.id ?? 'all')}
            />
            {anyFilter && (
              <Button kind="ghost" size="md" renderIcon={Close} onClick={clearFilters}>
                Clear filters
              </Button>
            )}
          </>
        }
      />
    </div>
  )
}
