import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router'
import { Loading, MenuButton, MenuItem } from '@carbon/react'
import { Activity, CheckmarkOutline, Time, PauseOutline, Warning, Money } from '@carbon/icons-react'
import { useStore, useProvisionedPackages } from '../store/store'
import { useAppTheme } from '../providers/ThemeProvider'
import { KpiCluster, KpiTile } from '../components/KpiCluster'
import { Card } from '../components/Card'
import { ChartCard, ChartLegend } from '../components/ChartCard'
import { EChart } from '../components/EChart'
import { CommitGraph } from '../components/CommitGraph'
import { EmptyState } from '../components/EmptyState'
import { MethodTag } from '../components/tags/MethodTag'
import { timeSeriesOption, legendItems } from '../lib/echartsOptions'
import { snapshotsToPlatformSeries, dailyToCodeFrequency, packageColorMap, packageTagTypeMap, OTHER_COLOR } from '../lib/chartData'
import { platformUsage, platformCodeFrequency, platformLogs } from '../lib/api'
import { TIME_RANGES, rangeOf, type RangeKey } from '../lib/timeRanges'
import { formatCompact, formatCurrency, formatCurrencyShort, formatInt, formatPercent } from '../lib/format'
import { formatDayMonth } from '../lib/time'
import { BillingTag } from '../components/tags/StatusTag'
import type { GitDailyActivity, Invoice, LogEntry, UsageSnapshot } from '../store/types'

const DAY_MS = 86_400_000
// Grid template for the dashboard invoice ledger (Package omitted — commits names it): Number ·
// Client · Amount · Due · Status. Sits at ~45% width beside the wider commits table.
const INVOICE_COLS = '118px minmax(0, 1fr) 92px 128px 100px'
const INVOICE_CAP = 5 // rows shown; empty slots pad to this so the card height is steady with fewer
// Recent request logs card: Package (link) · Method · Route · Status · ms. Time is omitted (the rows
// are newest-first, so order conveys recency) to keep the route readable in the narrow column.
const LOG_COLS = '78px 56px minmax(0, 1fr) 48px 48px'
const LOG_CAP = 12 // rows shown; empty slots pad to this so its height is steady

// Real platform KPIs over the selected window (driven by the global range picker). Totals are summed
// across the window; the deltas split the window in half (later vs earlier) for a within-window trend —
// for the 48h range that's exactly "last 24h vs prior 24h". Deltas are null when there's no earlier half.
interface UsageKpis {
  requests: number // total requests over the window
  errorRate: number // 0..1 over the window
  requestsDelta: number | null // later half vs earlier half, or null when there's no earlier half
  errorRateDelta: number | null // pp change, later half vs earlier half
}

function deriveUsageKpis(snapshots: UsageSnapshot[]): UsageKpis {
  if (snapshots.length === 0) return { requests: 0, errorRate: 0, requestsDelta: null, errorRateDelta: null }
  let min = Infinity, max = -Infinity
  for (const s of snapshots) {
    const t = new Date(s.period_start).getTime()
    if (t < min) min = t
    if (t > max) max = t
  }
  const mid = (min + max) / 2 // split the window into earlier/later halves
  let curReq = 0, curErr = 0, prevReq = 0, prevErr = 0
  for (const s of snapshots) {
    // request_count/error_count arrive as strings from the API — coerce so the running
    // totals are numeric rather than concatenated.
    const requests = Number(s.request_count)
    const errors = Number(s.error_count)
    if (new Date(s.period_start).getTime() >= mid) {
      curReq += requests
      curErr += errors
    } else {
      prevReq += requests
      prevErr += errors
    }
  }
  const totalReq = curReq + prevReq
  const errorRate = totalReq > 0 ? (curErr + prevErr) / totalReq : 0
  const hasPrior = prevReq > 0
  const requestsDelta = hasPrior ? (curReq - prevReq) / prevReq : null
  const errorRateDelta = hasPrior ? (curReq > 0 ? curErr / curReq : 0) - prevErr / prevReq : null
  return { requests: totalReq, errorRate, requestsDelta, errorRateDelta }
}

// Per-card usage fetch (its own time range). Polls every 30s; keeps the last good data on a refresh
// error so the chart doesn't blank out.
function useUsageSnapshots(range: RangeKey) {
  const [state, setState] = useState<{ loading: boolean; snapshots: UsageSnapshot[]; error: string | null }>({
    loading: true,
    snapshots: [],
    error: null,
  })
  useEffect(() => {
    let cancelled = false
    const r = rangeOf(range)
    const load = () => {
      platformUsage({ period: r.period, hours: r.days * 24 })
        .then((res) => {
          if (!cancelled) setState({ loading: false, snapshots: res.data ?? [], error: null })
        })
        .catch((e: unknown) => {
          if (!cancelled) setState((s) => ({ ...s, loading: false, error: e instanceof Error ? e.message : 'Failed to load usage' }))
        })
    }
    load()
    const t = setInterval(load, r.refreshMs)
    return () => {
      cancelled = true
      clearInterval(t)
    }
  }, [range])
  return state
}

// Per-card code-frequency fetch (its own time range, in days).
function useCodeFreq(range: RangeKey) {
  const [state, setState] = useState<{ loading: boolean; daily: GitDailyActivity[]; error: string | null }>({
    loading: true,
    daily: [],
    error: null,
  })
  useEffect(() => {
    let cancelled = false
    platformCodeFrequency({ days: rangeOf(range).days })
      .then((res) => {
        if (!cancelled) setState({ loading: false, daily: res.data ?? [], error: null })
      })
      .catch((e: unknown) => {
        if (!cancelled) setState((s) => ({ ...s, loading: false, error: e instanceof Error ? e.message : 'Failed to load commits' }))
      })
    return () => {
      cancelled = true
    }
  }, [range])
  return state
}

export function Dashboard() {
  const { theme } = useAppTheme()
  const navigate = useNavigate()
  const packages = useProvisionedPackages()
  const commits = useStore((s) => s.commits)
  const invoices = useStore((s) => s.invoices)
  const clients = useStore((s) => s.clients)
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

  const packageLabels = useMemo(() => new Map(packages.map((p) => [p.id, p.name])), [packages])
  // Stable package→colour map (shared with the charts) so a package's commit-graph lane matches its
  // colour in the Requests chart.
  const colorMap = useMemo(() => packageColorMap(packages), [packages])
  const tagTypeMap = useMemo(() => packageTagTypeMap(packages), [packages])

  // Single global time range — the picker top-right of the title drives every chart and the KPIs.
  const [range, setRange] = useState<RangeKey>('48h')
  const usage = useUsageSnapshots(range)
  const freq = useCodeFreq(range)

  // KPIs follow the selected window (deltas compare its later vs earlier half; null when too short).
  const kpis = useMemo(() => deriveUsageKpis(usage.snapshots), [usage.snapshots])

  // Charts render on ECharts (canvas) so we serve RAW data — bucket = fetch period (daily for year =
  // 365 pts, no weekly collapse). Canvas handles the density that choked Carbon's SVG. One fetch feeds
  // both the Requests (stacked) and Error rate (% by status) charts.
  const series = useMemo(() => snapshotsToPlatformSeries(usage.snapshots, packageLabels, rangeOf(range).period), [usage.snapshots, packageLabels, range])
  const groups = series.requests.groups
  const reqData = series.requests.data
  const errData = series.errorRate
  const freqData = useMemo(() => dailyToCodeFrequency(freq.daily), [freq.daily])

  const usageReady = !usage.loading && !usage.error && reqData.length > 0

  // ECharts options (canvas). Dashboard charts hide the x-axis for a compact look — the card subtitle
  // carries the window. Requests: stacked bars (top-4 packages + other). Error rate: stacked % area by
  // status class. Code frequency: diverging add/del bars per week.
  const reqEOption = useMemo(() => timeSeriesOption(reqData, { theme, type: 'bar', stack: true, groups }), [reqData, groups, theme])
  const errEOption = useMemo(() => timeSeriesOption(errData, { theme, type: 'line', area: true, stack: true, scale: { '4xx': '#d2a106', '5xx': '#fa4d56' }, yPercent: true }), [errData, theme])
  const freqEOption = useMemo(() => timeSeriesOption(freqData, { theme, type: 'bar', stack: true, scale: { additions: '#42be65', deletions: '#fa4d56' } }), [freqData, theme])

  return (
    <div className="scns-page">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 24 }}>
        <div>
          <h1 style={{ fontSize: 24, fontWeight: 400, margin: 0 }}>Dashboard</h1>
          <div className="scns-helper" style={{ marginTop: 6 }}>
            Platform overview across {packages.length} packages
          </div>
        </div>
        <MenuButton label={`Range · ${rangeOf(range).menu}`} kind="primary" size="md" menuAlignment="bottom-end">
          {TIME_RANGES.map((r) => (
            <MenuItem key={r.key} label={r.menu} onClick={() => setRange(r.key)} />
          ))}
        </MenuButton>
      </div>

      {/* KPI row */}
      <KpiCluster columns={6}>
        <KpiTile label="Active" value={counts.active} helper={`of ${packages.length}`} icon={<CheckmarkOutline size={20} />} iconClass="scns-val-green" />
        <KpiTile label="In grace" value={counts.grace} valueClass="scns-val-amber" helper="awaiting payment" icon={<Time size={20} />} iconClass="scns-val-amber" />
        <KpiTile label="Suspended" value={counts.suspended} valueClass="scns-val-red" helper="serving HTTP 402" icon={<PauseOutline size={20} />} iconClass={counts.suspended > 0 ? 'scns-val-red' : undefined} />
        <KpiTile
          label="Requests"
          value={formatCompact(kpis.requests)}
          helper={<RatioTrend ratio={kpis.requestsDelta} suffix="vs prior period" />}
          icon={<Activity size={20} />}
        />
        <KpiTile
          label="Error rate"
          value={formatPercent(kpis.errorRate, 1)}
          helper={<PointsTrend points={kpis.errorRateDelta} suffix="vs prior period" invert />}
          icon={<Warning size={20} />}
        />
        <KpiTile label="MRR" value={formatCurrencyShort(dash.mrr)} icon={<Money size={20} />} />
      </KpiCluster>

      {/* Charts: principal on the left, error rate + code frequency beside it. All three follow the
          global range picker; each card's subtitle echoes the applied window. */}
      <div className="scns-charts-row" style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 24, marginTop: 40 }}>
        <ChartCard
          title="Requests"
          subtitle={`stacked by package · ${rangeOf(range).subtitle}`}
          right={<ChartLegend items={legendItems(groups)} />}
          fill
        >
          {usageReady ? (
            <EChart option={reqEOption} height="100%" />
          ) : (
            <ChartPlaceholder loading={usage.loading} error={usage.error} height={400} />
          )}
        </ChartCard>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
          <ChartCard
            title="Error rate"
            subtitle={`4xx · 5xx, % of requests · ${rangeOf(range).subtitle}`}
            right={<ChartLegend items={[{ label: '4xx', color: '#d2a106' }, { label: '5xx', color: '#fa4d56' }]} />}
          >
            {usageReady ? (
              <EChart option={errEOption} height={150} />
            ) : (
              <ChartPlaceholder loading={usage.loading} error={usage.error} height={150} />
            )}
          </ChartCard>
          <ChartCard
            title="Code frequency"
            subtitle={`lines / week · ${rangeOf(range).subtitle}`}
            right={<ChartLegend items={[{ label: 'additions', color: '#42be65' }, { label: 'deletions', color: '#fa4d56' }]} />}
          >
            {freqData.length > 0 ? (
              <EChart option={freqEOption} height={150} />
            ) : (
              <EmptyState title={freq.error ? 'Insights unavailable' : freq.loading ? 'Loading…' : 'No commit history'} />
            )}
          </ChartCard>
        </div>
      </div>

      {/* Bottom row: two matching in-card tables side by side — fleet's recent commits (what shipped)
          on the left, recent invoices (money, overdue first) on the right. Collapses to one column
          under 1200px, where half-width gets too tight for the columns. */}
      <div className="scns-dash-tables">
        <Card title="Recent commits" subtitle="latest across packages" bodyPadding={0}>
          {commits.length === 0 ? (
            <EmptyState title="No commits yet" />
          ) : (
            <CommitGraph
              commits={commits.slice(0, 20)}
              cap={20}
              colorOf={(name) => (name ? colorMap[name] : undefined) ?? OTHER_COLOR}
              tagTypeOf={(name) => (name ? tagTypeMap[name] : undefined) ?? 'cool-gray'}
              onOpenPackage={(c) => navigate(c.package_id ? `/packages/${c.package_id}` : '/packages')}
            />
          )}
        </Card>
        {/* Right column: recent invoices (money, overdue first) stacked over recent request logs (the
            live tail across the fleet). Same in-card table chrome throughout. */}
        <div className="scns-dash-tables__col">
          <Card title="Recent invoices" subtitle="overdue first" bodyPadding={0}>
            <RecentInvoices
              invoices={invoices}
              clientName={(id) => clients.find((c) => c.id === id)?.name ?? '—'}
              onOpen={() => navigate('/invoices')}
            />
          </Card>
          <Card title="Recent requests" subtitle="latest across the fleet" bodyPadding={0}>
            <RecentLogs onOpen={() => navigate('/logs')} />
          </Card>
        </div>
      </div>
    </div>
  )
}

// Fleet invoice ledger for the dashboard: overdue rows float to the top (oldest due = most late
// first); the rest fall back to newest-issued. Capped to a glanceable window — the full list lives
// on /invoices.
function RecentInvoices({
  invoices,
  clientName,
  onOpen,
}: {
  invoices: Invoice[]
  clientName: (id: number) => string
  onOpen: () => void
}) {
  const rows = useMemo(() => {
    const rank = (i: Invoice) => (i.status === 'overdue' ? 0 : 1)
    return [...invoices]
      .sort((a, b) => {
        const r = rank(a) - rank(b)
        if (r !== 0) return r
        // both overdue → most-late first (oldest due); otherwise newest issued first
        if (a.status === 'overdue') return Date.parse(a.due_at) - Date.parse(b.due_at)
        return Date.parse(b.issued_at) - Date.parse(a.issued_at)
      })
      .slice(0, INVOICE_CAP)
  }, [invoices])

  if (rows.length === 0) return <EmptyState title="No invoices yet" />

  return (
    <div className="scns-cardtable">
      <div className="scns-cardtable__head" style={{ gridTemplateColumns: INVOICE_COLS }}>
        <span>Number</span>
        <span>Client</span>
        <span className="scns-cardtable__num">Amount</span>
        <span>Due</span>
        <span>Status</span>
      </div>
      {rows.map((i) => {
        const late = Math.floor((Date.now() - Date.parse(i.due_at)) / DAY_MS)
        const isOverdue = i.status === 'overdue'
        const isLatePending = i.status === 'pending' && late > 0
        const dueColor = isOverdue ? 'var(--scns-error)' : isLatePending ? 'var(--scns-warn-text)' : undefined
        return (
          <div key={i.id} className="scns-cardtable__row scns-cardtable__row--link" style={{ gridTemplateColumns: INVOICE_COLS }} onClick={onOpen}>
            <span className="scns-cardtable__cell scns-mono" style={{ color: 'var(--cds-link-primary)' }}>{i.number}</span>
            <span className="scns-cardtable__cell" style={{ color: 'var(--cds-text-primary)' }}>{clientName(i.client_id)}</span>
            <span className="scns-cardtable__cell scns-cardtable__num scns-num">{formatCurrency(i.amount)}</span>
            <span className="scns-cardtable__cell scns-mono" style={{ color: dueColor }}>
              {formatDayMonth(i.due_at)}
              {isOverdue && ` · ${late}d`}
              {isLatePending && ` · ${late}d late`}
            </span>
            <span className="scns-cardtable__cell"><BillingTag status={i.status} /></span>
          </div>
        )
      })}
      <CardTableFillers count={INVOICE_CAP - rows.length} />
    </div>
  )
}

// Empty row slots that pad an in-card table up to its cap, so a partially-filled card keeps a steady
// height and visibly reads as N slots (with dividers) rather than blank space.
function CardTableFillers({ count }: { count: number }) {
  if (count <= 0) return null
  return (
    <>
      {Array.from({ length: count }, (_, i) => (
        <div key={`filler-${i}`} className="scns-cardtable__row scns-cardtable__row--empty" aria-hidden="true" />
      ))}
    </>
  )
}

function logStatusColor(s: number): string {
  if (s < 300) return 'var(--scns-success)'
  if (s < 400) return 'var(--cds-text-helper)'
  if (s < 500) return 'var(--scns-warn-text)'
  return 'var(--scns-error)'
}

// Recent request logs: the live tail of commander_request_logs across the whole fleet (GET /logs, no
// package filter), newest first. Its own fetch + 15s poll, keeping the last good data on a refresh
// error so the card doesn't blank. Capped to a glance; the full explorer lives on /logs.
function RecentLogs({ onOpen }: { onOpen: () => void }) {
  const [state, setState] = useState<{ loading: boolean; logs: LogEntry[]; error: string | null }>({
    loading: true,
    logs: [],
    error: null,
  })
  useEffect(() => {
    let cancelled = false
    const load = () => {
      platformLogs({ limit: LOG_CAP })
        .then((res) => {
          if (!cancelled) setState({ loading: false, logs: res.data ?? [], error: null })
        })
        .catch((e: unknown) => {
          if (!cancelled) setState((s) => ({ ...s, loading: false, error: e instanceof Error ? e.message : 'Failed to load logs' }))
        })
    }
    load()
    const t = setInterval(load, 15_000)
    return () => {
      cancelled = true
      clearInterval(t)
    }
  }, [])

  if (state.logs.length === 0) {
    return <EmptyState title={state.error ? 'Logs unavailable' : state.loading ? 'Loading…' : 'No requests yet'} description={state.error ?? undefined} />
  }

  return (
    <div className="scns-cardtable">
      <div className="scns-cardtable__head" style={{ gridTemplateColumns: LOG_COLS }}>
        <span>Package</span>
        <span>Method</span>
        <span>Route</span>
        <span>Status</span>
        <span className="scns-cardtable__num">ms</span>
      </div>
      {state.logs.map((l) => (
        <div key={l.id} className="scns-cardtable__row scns-cardtable__row--link" style={{ gridTemplateColumns: LOG_COLS }} onClick={onOpen}>
          <span className="scns-cardtable__cell scns-mono" style={{ color: 'var(--cds-link-primary)' }}>{l.package ?? '—'}</span>
          <span className="scns-cardtable__cell"><MethodTag method={l.method} /></span>
          <span className="scns-cardtable__cell scns-mono">{l.route}</span>
          <span className="scns-cardtable__cell scns-mono" style={{ color: logStatusColor(l.status), fontWeight: 600 }}>{l.status}</span>
          <span className="scns-cardtable__cell scns-cardtable__num scns-num">{formatInt(l.duration_ms)}</span>
        </div>
      ))}
      <CardTableFillers count={LOG_CAP - state.logs.length} />
    </div>
  )
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

