import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router'
import { Loading, OverflowMenu, OverflowMenuItem, Table, TableHead, TableRow, TableHeader, TableBody, TableCell, TableContainer } from '@carbon/react'
import { Activity, CheckmarkOutline, Time, PauseOutline, Warning, Money } from '@carbon/icons-react'
import { useStore, useProvisionedPackages } from '../store/store'
import { useAppTheme } from '../providers/ThemeProvider'
import { useTick } from '../providers/TickProvider'
import { KpiCluster, KpiTile } from '../components/KpiCluster'
import { Card } from '../components/Card'
import { ChartCard, ChartLegend } from '../components/ChartCard'
import { EChart } from '../components/EChart'
import { RelativeTime } from '../components/RelativeTime'
import { EmptyState } from '../components/EmptyState'
import { timeSeriesOption, legendItems } from '../lib/echartsOptions'
import { snapshotsToPlatformSeries, dailyToCodeFrequency } from '../lib/chartData'
import { platformUsage, platformCodeFrequency } from '../lib/api'
import { TIME_RANGES, rangeOf, type RangeKey } from '../lib/timeRanges'
import { formatCompact, formatCurrencyShort, formatInt, formatPercent } from '../lib/format'
import type { GitDailyActivity, UsageSnapshot } from '../store/types'

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

// Isolated so the 1s tick re-renders only this line — not the whole dashboard
// (which would force every Carbon chart to re-layout each second).
function UpdatedClock() {
  const now = useTick()
  return (
    <div className="scns-mono scns-helper" style={{ fontSize: 11 }}>
      updated {new Date(now).toISOString().slice(11, 19)} UTC
    </div>
  )
}

export function Dashboard() {
  const { theme } = useAppTheme()
  const navigate = useNavigate()
  const packages = useProvisionedPackages()
  const commits = useStore((s) => s.commits)
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

  // Per-card time-range filters (each set from its own chart kebab). Each card fetches independently.
  const [reqRange, setReqRange] = useState<RangeKey>('48h')
  const [errRange, setErrRange] = useState<RangeKey>('48h')
  const [freqRange, setFreqRange] = useState<RangeKey>('year')

  const reqUsage = useUsageSnapshots(reqRange)
  const errUsage = useUsageSnapshots(errRange)
  const freq = useCodeFreq(freqRange)

  // KPIs (last 24h vs prior 24h) follow the Requests card's window — at 24h there's no prior window,
  // so the deltas read null (we never fabricate an arrow).
  const kpis = useMemo(() => deriveUsageKpis(reqUsage.snapshots), [reqUsage.snapshots])

  // Charts render on ECharts (canvas) so we serve RAW data — bucket = fetch period (daily for year =
  // 365 pts, no weekly collapse). Canvas handles the density that choked Carbon's SVG.
  const reqSeries = useMemo(() => snapshotsToPlatformSeries(reqUsage.snapshots, packageLabels, rangeOf(reqRange).period), [reqUsage.snapshots, packageLabels, reqRange])
  const errSeries = useMemo(() => snapshotsToPlatformSeries(errUsage.snapshots, packageLabels, rangeOf(errRange).period), [errUsage.snapshots, packageLabels, errRange])
  const groups = reqSeries.requests.groups
  const reqData = reqSeries.requests.data
  const errData = errSeries.errorRate
  const freqData = useMemo(() => dailyToCodeFrequency(freq.daily), [freq.daily])

  const reqReady = !reqUsage.loading && !reqUsage.error && reqData.length > 0
  const errReady = !errUsage.loading && !errUsage.error && errData.length > 0

  // ECharts options (canvas). Dashboard charts hide the x-axis for a compact look — the card subtitle
  // carries the window. Requests: stacked bars (top-4 packages + other). Error rate: stacked % area by
  // status class. Code frequency: diverging add/del bars per week.
  const reqEOption = useMemo(() => timeSeriesOption(reqData, { theme, type: 'bar', stack: true, groups }), [reqData, groups, theme])
  const errEOption = useMemo(() => timeSeriesOption(errData, { theme, type: 'line', area: true, stack: true, scale: { '4xx': '#d2a106', '5xx': '#fa4d56' }, yPercent: true }), [errData, theme])
  const freqEOption = useMemo(() => timeSeriesOption(freqData, { theme, type: 'bar', stack: true, scale: { additions: '#42be65', deletions: '#fa4d56' } }), [freqData, theme])

  // Kebab menu: the 5 time ranges + a link to the full Usage page. The applied range is shown by the
  // card subtitle (no per-item checkmark — OverflowMenuItem has no native selected state).
  const rangeMenu = (onChange: (k: RangeKey) => void) => (
    <OverflowMenu size="sm" flipped aria-label="Chart options">
      {TIME_RANGES.map((r) => (
        <OverflowMenuItem key={r.key} itemText={r.menu} onClick={() => onChange(r.key)} />
      ))}
      <OverflowMenuItem hasDivider itemText="Open in Usage" onClick={() => navigate('/usage')} />
    </OverflowMenu>
  )

  return (
    <div className="scns-page">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 24 }}>
        <div>
          <h1 style={{ fontSize: 24, fontWeight: 400, margin: 0 }}>Dashboard</h1>
          <div className="scns-helper" style={{ marginTop: 6 }}>
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
          helper={<RatioTrend ratio={kpis.requestsDelta} suffix="vs prior 24h" />}
          icon={<Activity size={20} />}
        />
        <KpiTile
          label="Error rate (24h)"
          value={formatPercent(kpis.errorRate, 1)}
          helper={<PointsTrend points={kpis.errorRateDelta} suffix="vs prior 24h" invert />}
          icon={<Warning size={20} />}
        />
        <KpiTile label="MRR" value={formatCurrencyShort(dash.mrr)} icon={<Money size={20} />} />
      </KpiCluster>

      {/* Charts: principal on the left, error rate + code frequency beside it. Each card's kebab
          carries its own time-range filter; the subtitle echoes the applied range. */}
      <div className="scns-charts-row" style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 24, marginTop: 40 }}>
        <ChartCard
          title="Requests"
          subtitle={`stacked by package · ${rangeOf(reqRange).subtitle}`}
          right={<ChartLegend items={legendItems(groups)} />}
          action={rangeMenu(setReqRange)}
          fill
        >
          {reqReady ? (
            <EChart option={reqEOption} height="100%" />
          ) : (
            <ChartPlaceholder loading={reqUsage.loading} error={reqUsage.error} height={400} />
          )}
        </ChartCard>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
          <ChartCard
            title="Error rate"
            subtitle={`4xx · 5xx, % of requests · ${rangeOf(errRange).subtitle}`}
            right={<ChartLegend items={[{ label: '4xx', color: '#d2a106' }, { label: '5xx', color: '#fa4d56' }]} />}
            action={rangeMenu(setErrRange)}
          >
            {errReady ? (
              <EChart option={errEOption} height={150} />
            ) : (
              <ChartPlaceholder loading={errUsage.loading} error={errUsage.error} height={150} />
            )}
          </ChartCard>
          <ChartCard
            title="Code frequency"
            subtitle={`lines / week · ${rangeOf(freqRange).subtitle}`}
            right={<ChartLegend items={[{ label: 'additions', color: '#42be65' }, { label: 'deletions', color: '#fa4d56' }]} />}
            action={rangeMenu(setFreqRange)}
          >
            {freqData.length > 0 ? (
              <EChart option={freqEOption} height={150} />
            ) : (
              <EmptyState title={freq.error ? 'Insights unavailable' : freq.loading ? 'Loading…' : 'No commit history'} />
            )}
          </ChartCard>
        </div>
      </div>

      {/* Recent commits across the fleet (replaces the old activity feed — triage now lives in the bell).
          Carbon DataTable, same shape as the package Insights tab + a Package column. */}
      <div style={{ marginTop: 40 }}>
        <Card title="Recent commits" subtitle="latest across packages" bodyPadding={0}>
          {commits.length === 0 ? (
            <EmptyState title="No commits yet" />
          ) : (
            <TableContainer>
              <Table size="sm">
                <TableHead>
                  <TableRow>
                    <TableHeader>Package</TableHeader>
                    <TableHeader>SHA</TableHeader>
                    <TableHeader>Message</TableHeader>
                    <TableHeader>Author</TableHeader>
                    <TableHeader>When</TableHeader>
                    <TableHeader style={{ textAlign: 'right' }}>+/−</TableHeader>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {commits.map((c) => (
                    <TableRow key={`${c.package_id}-${c.sha}`}>
                      <TableCell>
                        <button
                          type="button"
                          className="scns-linkbtn"
                          onClick={() => navigate(c.package_id ? `/packages/${c.package_id}` : '/packages')}
                        >
                          {c.package ?? '—'}
                        </button>
                      </TableCell>
                      <TableCell className="scns-mono">{c.sha.slice(0, 8)}</TableCell>
                      <TableCell>{(c.message ?? '').split('\n')[0] || '—'}</TableCell>
                      <TableCell>{c.author_name ?? '—'}</TableCell>
                      <TableCell>{c.committed_at ? <RelativeTime iso={c.committed_at} /> : '—'}</TableCell>
                      <TableCell style={{ textAlign: 'right' }} className="scns-num">
                        <span style={{ color: '#42be65' }}>+{formatInt(c.additions)}</span>{' '}
                        <span style={{ color: '#fa4d56' }}>−{formatInt(c.deletions)}</span>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </TableContainer>
          )}
        </Card>
      </div>
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

