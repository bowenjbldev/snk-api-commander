import { useMemo } from 'react'
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
import { useStore, useProvisionedPackages } from '../store/store'
import { useAppTheme } from '../providers/ThemeProvider'
import { PageHeader } from '../components/PageHeader'
import { ChartCard, ChartLegend } from '../components/ChartCard'
import { EChart } from '../components/EChart'
import { KpiCluster, KpiTile } from '../components/KpiCluster'
import { PackageStatusTag } from '../components/tags/StatusTag'
import { QuotaBar } from '../components/QuotaBar'
import { EmptyState } from '../components/EmptyState'
import { timeSeriesOption, legendItems } from '../lib/echartsOptions'
import { snapshotsToPlatformSeries, type PlatformSeries } from '../lib/chartData'
import { platformUsage } from '../lib/api'
import { formatCompact, formatInt, formatPercent } from '../lib/format'
import type { UsageSnapshot } from '../store/types'
import { useLoad } from '../hooks/useLoad'

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
  const packages = useProvisionedPackages()
  const dash = useStore((s) => s.dashboard)
  const packageLabels = useMemo(() => new Map(packages.map((p) => [p.id, p.name])), [packages])

  const platform = useLoad(() => platformUsage({ period: 'hour', hours: 48 }).then((res) => {
    const snapshots = res.data ?? []
    return { series: snapshotsToPlatformSeries(snapshots, packageLabels), kpis: deriveUsageKpis(snapshots) }
  }), [packageLabels])
  const series: PlatformSeries | null = platform.data?.series ?? null
  const kpis = platform.data?.kpis ?? { errorRate: 0, requestsDelta: null, errorRateDelta: null }

  const groups = useMemo(() => series?.requests.groups ?? [], [series])
  const reqData = useMemo(() => series?.requests.data ?? [], [series])
  const errData = useMemo(() => series?.errorRate ?? [], [series])
  const hasChart = !platform.loading && !platform.error && reqData.length > 0
  const reqEOption = useMemo(() => timeSeriesOption(reqData, { theme, type: 'bar', stack: true, groups, showX: true }), [reqData, groups, theme])
  const errEOption = useMemo(() => timeSeriesOption(errData, { theme, type: 'line', area: true, stack: true, scale: { '4xx': '#d2a106', '5xx': '#fa4d56' }, yPercent: true, showX: true }), [errData, theme])

  const top = useMemo(
    () => [...packages].sort((a, b) => b.requests_24h - a.requests_24h).slice(0, 8),
    [packages],
  )
  const clientName = useMemo(() => {
    const m = new Map(useStore.getState().clients.map((c) => [c.id, c.name]))
    return (id: number) => m.get(id) ?? 'Unknown client'
  }, [])

  return (
    <div className="scns-page">
      <PageHeader title="Usage" subtitle="Platform-wide rollups · UTC · last 48h" />

      <KpiCluster columns={4}>
        <KpiTile
          label="Requests (24h)"
          value={formatCompact(dash.requests_24h)}
          helper={<RatioTrend ratio={kpis.requestsDelta} suffix="vs prior 24h" />}
        />
        <KpiTile
          label="Error rate (24h)"
          value={formatPercent(kpis.errorRate, 1)}
          helper={<PointsTrend points={kpis.errorRateDelta} suffix="vs prior 24h" invert />}
        />
        <KpiTile label="Active packages" value={packages.filter((p) => p.status === 'active').length} helper={`of ${packages.length}`} />
        <KpiTile label="MRR" value={`$${formatInt(dash.mrr)}`} />
      </KpiCluster>

      <div className="scns-charts-row" style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 24, marginTop: 24 }}>
        <ChartCard
          title="Requests per hour"
          subtitle="last 48h, by package"
          right={<ChartLegend items={legendItems(groups)} />}
        >
          {hasChart ? (
            <EChart option={reqEOption} height={300} />
          ) : (
            <UsageChartPlaceholder loading={platform.loading} error={platform.error} />
          )}
        </ChartCard>
        <ChartCard
          title="Error rate"
          subtitle="%"
          right={<ChartLegend items={[{ label: '4xx', color: '#d2a106' }, { label: '5xx', color: '#fa4d56' }]} />}
        >
          {hasChart ? (
            <EChart option={errEOption} height={300} />
          ) : (
            <UsageChartPlaceholder loading={platform.loading} error={platform.error} />
          )}
        </ChartCard>
      </div>

      <div className="scns-card" style={{ marginTop: 24 }}>
        <div style={{ padding: '16px 16px 8px', fontSize: 16, fontWeight: 600 }}>Top packages by requests</div>
        <TableContainer>
          <Table size="md">
            <TableHead>
              <TableRow>
                <TableHeader>Package</TableHeader>
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
                    <EmptyState title="No active packages" />
                  </TableCell>
                </TableRow>
              ) : (
                top.map((p) => (
                  <TableRow key={p.id} style={{ cursor: 'pointer' }} onClick={() => navigate(`/packages/${p.id}`)}>
                    <TableCell className="scns-mono" style={{ color: 'var(--cds-link-primary)' }}>{p.name}</TableCell>
                    <TableCell>{clientName(p.client_id)}</TableCell>
                    <TableCell>
                      <PackageStatusTag pkg={p} />
                    </TableCell>
                    <TableCell style={{ textAlign: 'right' }} className="scns-num">{formatInt(p.requests_24h)}</TableCell>
                    <TableCell style={{ width: 160 }}>
                      <QuotaBar current={p.quota_health} limit={1} width={80} showLabel />
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
