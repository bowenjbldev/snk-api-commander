import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router'
import { Loading, OverflowMenu, OverflowMenuItem } from '@carbon/react'
import { Activity, CheckmarkOutline, Time, PauseOutline, Warning, Money } from '@carbon/icons-react'
import { StackedBarChart, AreaChart, SimpleBarChart } from '@carbon/charts-react'
import { useStore, useProvisionedPackages } from '../store/store'
import { useAppTheme } from '../providers/ThemeProvider'
import { useTick } from '../providers/TickProvider'
import { KpiCluster, KpiTile } from '../components/KpiCluster'
import { Card } from '../components/Card'
import { ChartCard, ChartLegend } from '../components/ChartCard'
import { GraceCountdown } from '../components/GraceCountdown'
import { RelativeTime } from '../components/RelativeTime'
import { EmptyState } from '../components/EmptyState'
import { stackedBarOptions, areaOptions, barOptions, legendItems } from '../lib/chartOptions'
import {
  snapshotsToPlatformSeries,
  requestsByPackageData,
  packageColorMap,
  type PlatformSeries,
} from '../lib/chartData'
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
    // request_count/error_count arrive as strings from the API — coerce so the running
    // totals are numeric rather than concatenated.
    const requests = Number(s.request_count)
    const errors = Number(s.error_count)
    const ageMs = now - new Date(s.period_start).getTime()
    if (ageMs < dayMs) {
      curReq += requests
      curErr += errors
    } else if (ageMs < 2 * dayMs) {
      prevReq += requests
      prevErr += errors
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
  // Principal chart (left): requests per hour timeseries.
  const reqOpts = useMemo(() => stackedBarOptions(theme, groups, '360px'), [theme, groups])
  // Two charts beside the principal (right column, stacked).
  const errOpts = useMemo(() => areaOptions(theme, { 'error %': '#fa4d56' }, '150px'), [theme])
  const pkgColors = useMemo(() => packageColorMap(packages), [packages])
  const reqByPkg = useMemo(() => requestsByPackageData(packages), [packages])
  const reqByPkgOpts = useMemo(() => barOptions(theme, pkgColors, '150px', true), [theme, pkgColors])

  const attention = useMemo(() => {
    const items: { tone: Tone; label: string; entity: string; desc: ReactNode; metric: ReactNode; to: string }[] = []
    for (const q of quotas) {
      if (q.limit_value != null && q.current_value / q.limit_value > 1) {
        const pkg = packages.find((p) => p.id === q.package_id)
        items.push({
          tone: 'red', label: 'Quota over', entity: packageLabel(q.package_id),
          desc: <>{q.metric_key} over limit</>, metric: formatPercent(q.current_value / q.limit_value),
          to: pkg ? `/packages/${pkg.id}/quotas` : '/packages',
        })
      }
    }
    for (const p of packages) {
      if (p.status === 'grace' && p.grace_until) {
        items.push({
          tone: 'amber', label: 'Grace ends', entity: p.name,
          desc: <>{p.reason ?? 'payment overdue'}</>, metric: <GraceCountdown until={p.grace_until} mode="short" />,
          to: `/packages/${p.id}`,
        })
      }
    }
    for (const i of invoices) {
      if (i.status === 'overdue') {
        items.push({
          tone: 'red', label: 'Overdue', entity: i.number,
          desc: <>linked {packageLabel(i.package_id)} {i.enforcement === 'suspended' ? 'suspended' : 'in grace'}</>,
          metric: formatCurrency(i.amount),
          to: '/invoices',
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
        <KpiTile label="Active" value={counts.active} helper={`of ${packages.length}`} icon={<CheckmarkOutline size={20} />} iconClass="scns-val-green" />
        <KpiTile label="In grace" value={counts.grace} valueClass="scns-val-amber" helper="awaiting payment" icon={<Time size={20} />} iconClass="scns-val-amber" />
        <KpiTile label="Suspended" value={counts.suspended} valueClass="scns-val-red" helper="serving HTTP 402" icon={<PauseOutline size={20} />} iconClass={counts.suspended > 0 ? 'scns-val-red' : undefined} />
        <KpiTile
          label="Requests (24h)"
          value={formatCompact(dash.requests_24h)}
          helper={<RatioTrend ratio={platform.kpis.requestsDelta} suffix="vs prior 24h" />}
          icon={<Activity size={20} />}
        />
        <KpiTile
          label="Error rate (24h)"
          value={formatPercent(platform.kpis.errorRate, 1)}
          helper={<PointsTrend points={platform.kpis.errorRateDelta} suffix="vs prior 24h" invert />}
          icon={<Warning size={20} />}
        />
        <KpiTile label="MRR" value={formatCurrencyShort(dash.mrr)} icon={<Money size={20} />} />
      </KpiCluster>

      {/* Charts: principal on the left, error rate + requests-24h beside it */}
      <div className="scns-charts-row" style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 24, marginTop: 40 }}>
        <ChartCard
          title="Requests per hour"
          subtitle="last 48h, stacked by package"
          right={<ChartLegend items={legendItems(groups)} />}
          action={
            <OverflowMenu size="sm" flipped aria-label="Chart actions">
              <OverflowMenuItem itemText="Open in Usage" onClick={() => navigate('/usage')} />
            </OverflowMenu>
          }
        >
          {hasChart ? (
            <StackedBarChart data={reqData} options={reqOpts} />
          ) : (
            <ChartPlaceholder loading={platform.loading} error={platform.error} height={360} />
          )}
        </ChartCard>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
          <ChartCard
            title="Requests · 24h"
            subtitle="by package"
            action={
              <OverflowMenu size="sm" flipped aria-label="Chart actions">
                <OverflowMenuItem itemText="Open in Usage" onClick={() => navigate('/usage')} />
              </OverflowMenu>
            }
          >
            <SimpleBarChart data={reqByPkg} options={reqByPkgOpts} />
          </ChartCard>
          <ChartCard
            title="Error rate"
            subtitle="%"
            right={<ChartLegend items={[{ label: 'error %', color: '#fa4d56' }]} />}
            action={
              <OverflowMenu size="sm" flipped aria-label="Chart actions">
                <OverflowMenuItem itemText="Open in Usage" onClick={() => navigate('/usage')} />
              </OverflowMenu>
            }
          >
            {hasChart ? (
              <AreaChart data={errData} options={errOpts} />
            ) : (
              <ChartPlaceholder loading={platform.loading} error={platform.error} height={150} />
            )}
          </ChartCard>
        </div>
      </div>

      {/* Triage: attention required + recent activity, side by side */}
      <div className="scns-grid-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 24, marginTop: 40 }}>
        {/* Attention required */}
        <Card title="Needs attention" subtitle="quotas, grace & billing" bodyPadding={0}>
          {attention.length === 0 ? (
              <EmptyState title="No issues detected" />
            ) : (
              attention.map((a, idx) => {
                const t = toneTint(a.tone)
                return (
                  <button
                    key={idx}
                    type="button"
                    onClick={() => navigate(a.to)}
                    className="scns-list-row"
                    style={{ borderTop: idx ? '1px solid var(--cds-border-subtle)' : 'none' }}
                  >
                    <span className="scns-list-tag" style={{ background: t.bg, color: t.fg }}>{a.label}</span>
                    <span className="scns-mono scns-list-entity">{a.entity}</span>
                    <span className="scns-list-detail">{a.desc}</span>
                    <span className="scns-list-value">{a.metric}</span>
                  </button>
                )
              })
            )}
        </Card>

        {/* Recent activity */}
        <Card title="Recent activity" subtitle="last 3 days" bodyPadding={0}>
          {activity.length === 0 ? (
              <EmptyState title="No recent activity" />
            ) : (
              activity.map((ev, idx) => {
                const m = activityMeta(ev, packageLabel, invoiceLabel)
                const t = toneTint(m.tone)
                return (
                  <button
                    key={ev.id}
                    type="button"
                    onClick={() => navigate(m.to)}
                    className="scns-list-row"
                    style={{ borderTop: idx ? '1px solid var(--cds-border-subtle)' : 'none' }}
                  >
                    <span className="scns-list-tag" style={{ background: t.bg, color: t.fg }}>{m.label}</span>
                    <span className="scns-mono scns-list-entity">{m.entity}</span>
                    <span className="scns-list-detail">{m.detail}</span>
                    <RelativeTime iso={ev.created_at} />
                  </button>
                )
              })
            )}
        </Card>
      </div>
    </div>
  )
}

// Shared status tone for the dense list rows (Attention + Activity). The tint tokens carry both
// themes, so the pill stays legible in g100 and g10.
type Tone = 'red' | 'amber' | 'green' | 'blue'

function toneTint(tone: Tone): { bg: string; fg: string } {
  switch (tone) {
    case 'red':
      return { bg: 'var(--scns-tag-red-bg)', fg: 'var(--scns-tag-red-text)' }
    case 'amber':
      return { bg: 'var(--scns-amber-bg)', fg: 'var(--scns-amber-text)' }
    case 'green':
      return { bg: 'var(--scns-method-green-bg)', fg: 'var(--scns-method-green-text)' }
    case 'blue':
      return { bg: 'var(--scns-method-blue-bg)', fg: 'var(--scns-method-blue-text)' }
  }
}

interface ActivityMeta {
  tone: Tone
  label: string
  entity: string
  detail: string
  to: string
}

// Infers the origin of a state transition from its `reason` (no actor is stored anymore). Automated
// reasons (`quota_exceeded:*`, `payment_overdue`) read as system-driven; everything else as operator.
function reasonToActor(reason: ActivityEvent['reason']): string {
  if (!reason) return 'operator'
  if (reason === 'payment_overdue' || reason.startsWith('quota_exceeded')) return 'auto'
  return 'operator'
}

// Maps an activity event to the same row shape Attention uses (label · entity · detail · value),
// so both lists read identically. The label pill carries the category; tone mirrors severity. The
// transition origin is derived from `reason` (cliente-primero model: no `by` actor is stored).
function activityMeta(
  ev: ActivityEvent,
  packageLabel: (id: number | undefined) => string,
  invoiceLabel: (id: number | undefined) => string,
): ActivityMeta {
  const pkg = packageLabel(ev.package_id)
  const pkgTo = ev.package_id != null ? `/packages/${ev.package_id}` : '/packages'
  switch (ev.kind) {
    case 'suspend':
      return { tone: 'red', label: 'Suspended', entity: pkg, detail: `${ev.reason ?? 'manual'} · ${reasonToActor(ev.reason)}`, to: pkgTo }
    case 'grace':
      return { tone: 'amber', label: 'Grace', entity: pkg, detail: `${ev.reason ?? 'payment overdue'} · ${reasonToActor(ev.reason)}`, to: pkgTo }
    case 'resume':
      return { tone: 'green', label: 'Resumed', entity: pkg, detail: ev.message ?? 'service restored', to: pkgTo }
    case 'paid':
      return { tone: 'green', label: 'Paid', entity: invoiceLabel(ev.invoice_id), detail: ev.message ?? 'settled', to: '/invoices' }
    case 'quota':
      return { tone: 'amber', label: 'Quota', entity: pkg, detail: ev.message ?? 'limit exceeded', to: pkgTo }
    case 'change_plan':
      return { tone: 'blue', label: 'Plan', entity: pkg, detail: ev.reason ? `to ${ev.reason.replace(/^plan:/, '')}` : 'updated', to: pkgTo }
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

