import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router'
import { Tag, Loading } from '@carbon/react'
import { StackedBarChart, AreaChart } from '@carbon/charts-react'
import { useStore, useProvisionedPackages } from '../store/store'
import { useAppTheme } from '../providers/ThemeProvider'
import { useTick } from '../providers/TickProvider'
import { KpiCluster, KpiTile } from '../components/KpiCluster'
import { ChartCard, ChartLegend } from '../components/ChartCard'
import { GraceCountdown } from '../components/GraceCountdown'
import { RelativeTime } from '../components/RelativeTime'
import { EmptyState } from '../components/EmptyState'
import { stackedBarOptions, areaOptions, legendItems } from '../lib/chartOptions'
import { snapshotsToPlatformSeries, type PlatformSeries } from '../lib/chartData'
import { platformUsage } from '../lib/api'
import { formatCompact, formatCurrencyShort, formatCurrency, formatPercent } from '../lib/format'
import type { ActivityEvent, UsageSnapshot } from '../store/types'

// Real platform KPIs derived from the usage-snapshot cache. Error rate is the aggregate
// errors/requests over the most recent 24h; the deltas compare that window against the prior
// 24h of the same 48h series (the only period-over-period signal the snapshot feed gives us).
interface UsageKpis {
  errorRate: number // 0..1 over the last 24h
  requestsDelta: number | null // ratio vs prior 24h, or null when there's no prior window
  errorRateDelta: number | null // pp change vs prior 24h, or null when there's no prior window
}

function deriveUsageKpis(snapshots: UsageSnapshot[]): UsageKpis {
  if (snapshots.length === 0) return { errorRate: 0, requestsDelta: null, errorRateDelta: null }
  const now = Date.now()
  const dayMs = 24 * 3_600_000
  let curReq = 0, curErr = 0, prevReq = 0, prevErr = 0
  for (const s of snapshots) {
    const ageMs = now - new Date(s.period_start).getTime()
    if (ageMs < dayMs) {
      curReq += s.request_count
      curErr += s.error_count
    } else if (ageMs < 2 * dayMs) {
      prevReq += s.request_count
      prevErr += s.error_count
    }
  }
  const errorRate = curReq > 0 ? curErr / curReq : 0
  const hasPrior = prevReq > 0
  const requestsDelta = hasPrior ? (curReq - prevReq) / prevReq : null
  const errorRateDelta = hasPrior ? errorRate - prevErr / prevReq : null
  return { errorRate, requestsDelta, errorRateDelta }
}

// Isolated so the 1s tick re-renders only this line — not the whole dashboard
// (which would force every Carbon chart to re-layout each second).
function UpdatedClock() {
  const now = useTick()
  return (
    <div className="scns-mono scns-helper" style={{ fontSize: 11 }}>
      updated {new Date(now).toISOString().slice(11, 19)} UTC · auto-refresh 30s
    </div>
  )
}

export function Dashboard() {
  const { theme } = useAppTheme()
  const navigate = useNavigate()
  const packages = useProvisionedPackages()
  const invoices = useStore((s) => s.invoices)
  const quotas = useStore((s) => s.quotas)
  const activity = useStore((s) => s.activity)
  const dash = useStore((s) => s.dashboard)

  const counts = useMemo(() => {
    let active = 0, grace = 0, suspended = 0
    for (const p of packages) {
      if (p.status === 'active') active++
      else if (p.status === 'grace') grace++
      else suspended++
    }
    return { active, grace, suspended }
  }, [packages])

  // Real platform usage from the Commander snapshot cache (seeded + refreshed by package sync).
  const [platform, setPlatform] = useState<{
    loading: boolean
    series: PlatformSeries | null
    kpis: UsageKpis
    error: string | null
  }>({
    loading: true,
    series: null,
    kpis: { errorRate: 0, requestsDelta: null, errorRateDelta: null },
    error: null,
  })
  const packageLabels = useMemo(() => new Map(packages.map((p) => [p.id, p.name])), [packages])
  const invoiceLabels = useMemo(() => new Map(invoices.map((i) => [i.id, i.number])), [invoices])
  const packageLabel = useCallback(
    (id: number | undefined) => (id == null ? 'Unknown package' : packageLabels.get(id) ?? `package ${id}`),
    [packageLabels],
  )
  const invoiceLabel = useCallback(
    (id: number | undefined) => (id == null ? 'Unknown invoice' : invoiceLabels.get(id) ?? `invoice ${id}`),
    [invoiceLabels],
  )

  useEffect(() => {
    let cancelled = false
    const load = () => {
      platformUsage({ period: 'hour', hours: 48 })
        .then((res) => {
          const snapshots = res.data ?? []
          if (!cancelled) {
            setPlatform({ loading: false, series: snapshotsToPlatformSeries(snapshots, packageLabels), kpis: deriveUsageKpis(snapshots), error: null })
          }
        })
        .catch((e: unknown) => {
          // Keep the last good series on a refresh error so the chart doesn't blank out.
          if (!cancelled) setPlatform((p) => ({ ...p, loading: false, error: e instanceof Error ? e.message : 'Failed to load usage' }))
        })
    }
    load()
    const timer = setInterval(load, 30_000) // honours the "auto-refresh 30s" label
    return () => {
      cancelled = true
      clearInterval(timer)
    }
  }, [packageLabels])

  const groups = useMemo(() => platform.series?.requests.groups ?? [], [platform.series])
  const reqData = platform.series?.requests.data ?? []
  const errData = platform.series?.errorRate ?? []
  const hasChart = !platform.loading && !platform.error && reqData.length > 0
  const reqOpts = useMemo(() => stackedBarOptions(theme, groups, '320px'), [theme, groups])
  const errOpts = useMemo(() => areaOptions(theme, { 'error %': '#fa4d56' }, '320px'), [theme])

  const attention = useMemo(() => {
    const items: { tone: 'red' | 'amber'; sev: string; entity: string; desc: ReactNode; cta: string; to: string }[] = []
    for (const q of quotas) {
      if (q.limit_value != null && q.current_value / q.limit_value > 1) {
        const pkg = packages.find((p) => p.id === q.package_id)
        items.push({
          tone: 'red', sev: 'QUOTA OVER', entity: packageLabel(q.package_id),
          desc: <>{q.metric_key} at {formatPercent(q.current_value / q.limit_value)} of limit</>,
          cta: 'Review quota ->', to: pkg ? `/packages/${pkg.name}/quotas` : '/packages',
        })
      }
    }
    for (const p of packages) {
      if (p.status === 'grace' && p.grace_until) {
        items.push({
          tone: 'amber', sev: 'GRACE ENDS', entity: p.name,
          desc: <>suspends in <GraceCountdown until={p.grace_until} /> · {p.reason}</>,
          cta: 'Open package ->', to: `/packages/${p.name}`,
        })
      }
    }
    for (const i of invoices) {
      if (i.status === 'overdue') {
        items.push({
          tone: 'red', sev: 'OVERDUE', entity: i.number,
          desc: <>{formatCurrency(i.amount)} — linked {packageLabel(i.package_id)} {i.enforcement === 'suspended' ? 'suspended' : 'in grace'}</>,
          cta: 'Open invoice ->', to: '/invoices',
        })
      }
    }
    return items
  }, [quotas, packages, invoices, packageLabel])

  return (
    <div className="scns-page">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 24 }}>
        <div>
          <h1 style={{ fontSize: 24, fontWeight: 400, margin: 0 }}>Dashboard</h1>
          <div className="scns-helper" style={{ fontSize: 13, marginTop: 6 }}>
            Platform overview across {packages.length} packages
          </div>
        </div>
        <UpdatedClock />
      </div>

      {/* KPI row */}
      <KpiCluster columns={6}>
        <KpiTile label="Active" value={counts.active} helper={`of ${packages.length}`} />
        <KpiTile label="In grace" value={counts.grace} valueClass="scns-val-amber" helper="awaiting payment" />
        <KpiTile label="Suspended" value={counts.suspended} valueClass="scns-val-red" helper="serving HTTP 402" />
        <KpiTile
          label="Requests (24h)"
          value={formatCompact(dash.requests_24h)}
          helper={<RatioTrend ratio={platform.kpis.requestsDelta} suffix="vs prior 24h" />}
        />
        <KpiTile
          label="Error rate (24h)"
          value={formatPercent(platform.kpis.errorRate, 1)}
          helper={<PointsTrend points={platform.kpis.errorRateDelta} suffix="vs prior 24h" invert />}
        />
        <KpiTile label="MRR" value={formatCurrencyShort(dash.mrr)} />
      </KpiCluster>

      {/* Attention required */}
      <div style={{ marginTop: 32 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
          <span style={{ fontSize: 16, fontWeight: 600 }}>Attention required</span>
          <Tag type="red" size="sm">{attention.length}</Tag>
        </div>
        <div className="scns-card">
          {attention.length === 0 ? (
            <EmptyState title="No issues detected" />
          ) : (
            attention.map((a, idx) => (
              <button
                key={idx}
                type="button"
                onClick={() => navigate(a.to)}
                style={{
                  display: 'flex', alignItems: 'center', gap: 16, width: '100%', height: 48, padding: '0 16px',
                  background: 'none', border: 'none', borderTop: idx ? '1px solid var(--cds-border-subtle)' : 'none',
                  cursor: 'pointer', textAlign: 'left',
                }}
              >
                <Pill tone={a.tone}>{a.sev}</Pill>
                <span className="scns-mono" style={{ color: 'var(--cds-link-primary)', minWidth: 140 }}>{a.entity}</span>
                <span style={{ flex: 1, fontSize: 13, color: 'var(--cds-text-secondary)' }}>{a.desc}</span>
                <span className="scns-link" style={{ fontSize: 13 }}>{a.cta}</span>
              </button>
            ))
          )}
        </div>
      </div>

      {/* Charts row */}
      <div className="scns-charts-row" style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 24, marginTop: 32 }}>
        <ChartCard
          title="Requests per hour"
          subtitle="last 48h, stacked by package"
          right={<ChartLegend items={legendItems(groups)} />}
        >
          {hasChart ? (
            <StackedBarChart data={reqData} options={reqOpts} />
          ) : (
            <ChartPlaceholder loading={platform.loading} error={platform.error} height={320} />
          )}
        </ChartCard>
        <ChartCard
          title="Error rate"
          subtitle="%"
          right={<ChartLegend items={[{ label: 'error %', color: '#fa4d56' }]} />}
        >
          {hasChart ? (
            <AreaChart data={errData} options={errOpts} />
          ) : (
            <ChartPlaceholder loading={platform.loading} error={platform.error} height={320} />
          )}
        </ChartCard>
      </div>

      {/* Recent activity */}
      <div style={{ marginTop: 32 }}>
        <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginBottom: 12 }}>
          <span style={{ fontSize: 16, fontWeight: 600 }}>Recent activity</span>
          <span className="scns-helper" style={{ fontSize: 13 }}>
            state changes, last 3 days
          </span>
        </div>
        <div className="scns-card">
          {activity.length === 0 ? (
            <EmptyState title="No recent activity" />
          ) : (
            activity.map((ev, idx) => (
              <div
                key={ev.id}
                style={{
                  display: 'flex', alignItems: 'center', gap: 12, height: 44, padding: '0 16px',
                  borderTop: idx ? '1px solid var(--cds-border-subtle)' : 'none',
                }}
              >
                <span style={{ width: 8, height: 8, borderRadius: '50%', background: dotColor(ev.kind), flex: 'none' }} aria-hidden="true" />
                <span style={{ flex: 1, fontSize: 13 }}>{activitySentence(ev, packageLabel, invoiceLabel)}</span>
                <RelativeTime iso={ev.at} />
              </div>
            ))
          )}
        </div>
      </div>
    </div>
  )
}

function dotColor(kind: ActivityEvent['kind']): string {
  if (kind === 'suspend') return 'var(--scns-error)'
  if (kind === 'grace' || kind === 'quota') return 'var(--scns-warning)'
  return 'var(--scns-success)'
}

function activitySentence(
  ev: ActivityEvent,
  packageLabel: (id: number | undefined) => string,
  invoiceLabel: (id: number | undefined) => string,
): ReactNode {
  const ent = (s?: string | number) => <span className="scns-mono" style={{ color: 'var(--cds-link-primary)' }}>{s}</span>
  switch (ev.kind) {
    case 'suspend':
      return <>{ent(packageLabel(ev.package_id))} suspended — {ev.reason} · by {ev.by}</>
    case 'grace':
      return <>{ent(packageLabel(ev.package_id))} moved to grace — {ev.reason} · by {ev.by}</>
    case 'resume':
      return <>{ent(packageLabel(ev.package_id))} resumed · by {ev.by}</>
    case 'paid':
      return <>{ent(invoiceLabel(ev.invoice_id))} marked paid{ev.message ? ` — ${ev.message}` : ''}</>
    case 'quota':
      return <>{ent(packageLabel(ev.package_id))} {ev.message}</>
  }
}

function ChartPlaceholder({ loading, error, height }: { loading: boolean; error: string | null; height: number }) {
  return (
    <div style={{ height, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      {loading ? (
        <Loading withOverlay={false} small description="Loading usage…" />
      ) : error ? (
        <EmptyState title="Usage unavailable" description={error} />
      ) : (
        <EmptyState title="No usage data yet" />
      )}
    </div>
  )
}

// Period-over-period trend (last 24h vs prior 24h). Rendered only when a prior window exists;
// nothing is shown otherwise so the operator never sees a fabricated arrow.
function RatioTrend({ ratio, suffix }: { ratio: number | null; suffix: string }) {
  if (ratio == null) return null
  const up = ratio >= 0
  return (
    <span className={up ? 'scns-val-green' : 'scns-val-red'}>
      {up ? '▲' : '▼'} {formatPercent(Math.abs(ratio))} {suffix}
    </span>
  )
}

// Trend expressed in percentage points (for rate-style KPIs). `invert` flips the color semantics
// so that a rising error rate reads red.
function PointsTrend({ points, suffix, invert }: { points: number | null; suffix: string; invert?: boolean }) {
  if (points == null) return null
  const up = points >= 0
  const good = invert ? !up : up
  return (
    <span className={good ? 'scns-val-green' : 'scns-val-red'}>
      {up ? '▲' : '▼'} {formatPercent(Math.abs(points), 1)} {suffix}
    </span>
  )
}

function Pill({ tone, children }: { tone: 'red' | 'amber'; children: ReactNode }) {
  const bg = tone === 'red' ? 'var(--scns-tag-red-bg)' : 'var(--scns-amber-bg)'
  const color = tone === 'red' ? 'var(--scns-tag-red-text)' : 'var(--scns-amber-text)'
  return (
    <span
      className="scns-mono"
      style={{
        fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.4, background: bg, color,
        padding: '3px 8px', borderRadius: 24, flex: 'none', minWidth: 92, textAlign: 'center',
      }}
    >
      {children}
    </span>
  )
}
