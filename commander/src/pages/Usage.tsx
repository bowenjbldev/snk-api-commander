import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router'
import {
  Loading,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableHeader,
  TableRow,
} from '@carbon/react'
import { StackedBarChart, AreaChart } from '@carbon/charts-react'
import { useStore } from '../store/store'
import { useAppTheme } from '../providers/ThemeProvider'
import { PageHeader } from '../components/PageHeader'
import { ChartCard, ChartLegend } from '../components/ChartCard'
import { KpiCluster, KpiTile } from '../components/KpiCluster'
import { DeploymentStatusTag } from '../components/tags/StatusTag'
import { QuotaBar } from '../components/QuotaBar'
import { EmptyState } from '../components/EmptyState'
import { stackedBarOptions, areaOptions, legendItems } from '../lib/chartOptions'
import { snapshotsToPlatformSeries, type PlatformSeries } from '../lib/chartData'
import { platformUsage } from '../lib/api'
import { formatCompact, formatInt, formatPercent } from '../lib/format'
import type { UsageSnapshot } from '../store/types'

// Real platform KPIs derived from the usage-snapshot cache: aggregate error rate over the most
// recent 24h, plus period-over-period deltas vs the prior 24h of the same 48h series. Deltas are
// null when there's no prior window, so the operator never sees a fabricated trend.
interface UsageKpis {
  errorRate: number // 0..1 over the last 24h
  requestsDelta: number | null // ratio vs prior 24h
  errorRateDelta: number | null // pp change vs prior 24h
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
  return {
    errorRate,
    requestsDelta: hasPrior ? (curReq - prevReq) / prevReq : null,
    errorRateDelta: hasPrior ? errorRate - prevErr / prevReq : null,
  }
}

export function Usage() {
  const { theme } = useAppTheme()
  const navigate = useNavigate()
  const deployments = useStore((s) => s.deployments)
  const dash = useStore((s) => s.dashboard)

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
  useEffect(() => {
    let cancelled = false
    platformUsage({ period: 'hour', hours: 48 })
      .then((res) => {
        const snapshots = res.data ?? []
        if (!cancelled) setPlatform({ loading: false, series: snapshotsToPlatformSeries(snapshots), kpis: deriveUsageKpis(snapshots), error: null })
      })
      .catch((e: unknown) => {
        if (!cancelled) setPlatform({ loading: false, series: null, kpis: { errorRate: 0, requestsDelta: null, errorRateDelta: null }, error: e instanceof Error ? e.message : 'Failed to load usage' })
      })
    return () => {
      cancelled = true
    }
  }, [])

  const groups = useMemo(() => platform.series?.requests.groups ?? [], [platform.series])
  const reqData = platform.series?.requests.data ?? []
  const errData = platform.series?.errorRate ?? []
  const hasChart = !platform.loading && !platform.error && reqData.length > 0
  const reqOpts = useMemo(() => stackedBarOptions(theme, groups, '300px'), [theme, groups])
  const errOpts = useMemo(() => areaOptions(theme, { 'error %': '#fa4d56' }, '300px'), [theme])

  const top = useMemo(
    () => [...deployments].sort((a, b) => b.requests_24h - a.requests_24h).slice(0, 8),
    [deployments],
  )
  const clientName = useMemo(() => {
    const m = new Map(useStore.getState().clients.map((c) => [c.id, c.name]))
    return (id: number) => m.get(id) ?? String(id)
  }, [])

  return (
    <div className="scns-page">
      <PageHeader title="Usage" subtitle="Platform-wide rollups · UTC · last 48h" />

      <KpiCluster columns={4}>
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
        <KpiTile label="Active deployments" value={deployments.filter((d) => d.package.status === 'active').length} helper={`of ${deployments.length}`} />
        <KpiTile label="MRR" value={`$${formatInt(dash.mrr)}`} />
      </KpiCluster>

      <div className="scns-charts-row" style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 24, marginTop: 24 }}>
        <ChartCard
          title="Requests per hour"
          subtitle="last 48h, stacked by deployment"
          right={<ChartLegend items={legendItems(groups)} />}
        >
          {hasChart ? (
            <StackedBarChart data={reqData} options={reqOpts} />
          ) : (
            <UsageChartPlaceholder loading={platform.loading} error={platform.error} />
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
            <UsageChartPlaceholder loading={platform.loading} error={platform.error} />
          )}
        </ChartCard>
      </div>

      <div className="scns-card" style={{ marginTop: 24 }}>
        <div style={{ padding: '16px 16px 8px', fontSize: 16, fontWeight: 600 }}>Top deployments by requests</div>
        <TableContainer>
          <Table size="md">
            <TableHead>
              <TableRow>
                <TableHeader>Deployment</TableHeader>
                <TableHeader>Client</TableHeader>
                <TableHeader>State</TableHeader>
                <TableHeader style={{ textAlign: 'right' }}>Req (24h)</TableHeader>
                <TableHeader>Quota health</TableHeader>
              </TableRow>
            </TableHead>
            <TableBody>
              {top.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={5}>
                    <EmptyState title="No active deployments" />
                  </TableCell>
                </TableRow>
              ) : (
                top.map((d) => (
                  <TableRow key={d.id} style={{ cursor: 'pointer' }} onClick={() => navigate(`/packages/${d.package.name}`)}>
                    <TableCell className="scns-mono" style={{ color: 'var(--cds-link-primary)' }}>{d.package.name}</TableCell>
                    <TableCell>{clientName(d.client_id)}</TableCell>
                    <TableCell>
                      <DeploymentStatusTag pkg={d.package} />
                    </TableCell>
                    <TableCell style={{ textAlign: 'right' }} className="scns-num">{formatInt(d.requests_24h)}</TableCell>
                    <TableCell style={{ width: 160 }}>
                      <QuotaBar current={d.quota_health} limit={1} width={80} showLabel />
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </TableContainer>
      </div>
    </div>
  )
}

// Period-over-period trend (last 24h vs prior 24h). Rendered only when a prior window exists.
function RatioTrend({ ratio, suffix }: { ratio: number | null; suffix: string }) {
  if (ratio == null) return null
  const up = ratio >= 0
  return (
    <span className={up ? 'scns-val-green' : 'scns-val-red'}>
      {up ? '▲' : '▼'} {formatPercent(Math.abs(ratio))} {suffix}
    </span>
  )
}

// Trend in percentage points (for rate-style KPIs). `invert` flips color semantics so a rising
// error rate reads red.
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

function UsageChartPlaceholder({ loading, error }: { loading: boolean; error: string | null }) {
  return (
    <div style={{ height: 300, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
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
