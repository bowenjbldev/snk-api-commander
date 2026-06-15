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
import { stackedBarOptions, areaOptions, legendItems } from '../lib/chartOptions'
import { snapshotsToPlatformSeries, type PlatformSeries } from '../lib/chartData'
import { platformUsage } from '../lib/api'
import { formatCompact, formatInt, formatPercent } from '../lib/format'

export function Usage() {
  const { theme } = useAppTheme()
  const navigate = useNavigate()
  const deployments = useStore((s) => s.deployments)
  const dash = useStore((s) => s.dashboard)

  const [platform, setPlatform] = useState<{ loading: boolean; series: PlatformSeries | null; error: string | null }>({
    loading: true,
    series: null,
    error: null,
  })
  useEffect(() => {
    let cancelled = false
    platformUsage({ period: 'hour', hours: 48 })
      .then((res) => {
        if (!cancelled) setPlatform({ loading: false, series: snapshotsToPlatformSeries(res.data ?? []), error: null })
      })
      .catch((e: unknown) => {
        if (!cancelled) setPlatform({ loading: false, series: null, error: e instanceof Error ? e.message : 'Failed to load usage' })
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
    return (id: string) => m.get(id) ?? id
  }, [])

  return (
    <div className="scns-page">
      <PageHeader title="Usage" subtitle="Platform-wide rollups · UTC · last 48h" />

      <KpiCluster columns={4}>
        <KpiTile label="Requests (24h)" value={formatCompact(dash.requests_24h)} helper={<span className="scns-val-green">▲ {formatPercent(dash.requests_delta)}</span>} />
        <KpiTile label="Error rate (24h)" value={formatPercent(dash.error_rate, 1)} helper={<span className="scns-val-red">▲ {formatPercent(dash.error_rate_delta, 1)}</span>} />
        <KpiTile label="Active deployments" value={deployments.filter((d) => d.state.status === 'active').length} helper={`of ${deployments.length}`} />
        <KpiTile label="MRR" value={`$${formatInt(dash.mrr)}`} helper={<span className="scns-val-green">▲ ${formatInt(dash.mrr_delta)}</span>} />
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
        <ChartCard title="Error rate" subtitle="4xx + 5xx">
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
              {top.map((d) => (
                <TableRow key={d.id} style={{ cursor: 'pointer' }} onClick={() => navigate(`/deployments/${d.id}`)}>
                  <TableCell className="scns-mono" style={{ color: 'var(--cds-link-primary)' }}>{d.name}</TableCell>
                  <TableCell>{clientName(d.client_id)}</TableCell>
                  <TableCell>
                    <DeploymentStatusTag state={d.state} />
                  </TableCell>
                  <TableCell style={{ textAlign: 'right' }} className="scns-num">{formatInt(d.requests_24h)}</TableCell>
                  <TableCell style={{ width: 160 }}>
                    <QuotaBar current={d.quota_health} limit={1} width={80} showLabel />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      </div>
    </div>
  )
}

function UsageChartPlaceholder({ loading, error }: { loading: boolean; error: string | null }) {
  return (
    <div style={{ height: 300, display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--cds-text-secondary)', fontSize: 13 }}>
      {loading ? <Loading withOverlay={false} small description="Loading usage…" /> : error ? `Usage unavailable: ${error}` : 'No usage data yet'}
    </div>
  )
}
