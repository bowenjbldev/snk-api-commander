import { useMemo, useState } from 'react'
import { useOutletContext } from 'react-router'
import {
  Button,
  ContentSwitcher,
  Loading,
  Switch,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableHeader,
  TableRow,
} from '@carbon/react'
import { Calendar, Download } from '@carbon/icons-react'
import type { Package, PackageContext } from '../../store/types'
import { useAppTheme } from '../../providers/ThemeProvider'
import { useToast } from '../../providers/ToastProvider'
import { ChartCard, ChartLegend } from '../../components/ChartCard'
import { EChart } from '../../components/EChart'
import { EmptyState } from '../../components/EmptyState'
import { MethodTag } from '../../components/tags/MethodTag'
import { KpiCluster, KpiTile } from '../../components/KpiCluster'
import { timeSeriesOption } from '../../lib/echartsOptions'
import { rollupsToUsageSeries } from '../../lib/chartData'
import { packagesApi } from '../../lib/api'
import { formatBytes, formatCompact, formatInt, formatPercent } from '../../lib/format'
import { useLoad } from '../../hooks/useLoad'

function exportCsv(filename: string, rows: (string | number)[][]) {
  const csv = rows.map((r) => r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n')
  const blob = new Blob([csv], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
}

export function UsageTab() {
  const { packageRow } = useOutletContext<PackageContext>()

  if (!packageRow) {
    return (
      <div className="scns-card">
        <EmptyState
          title="No live instance yet — provision one to see usage"
          description="Once this package is provisioned, in-process metering rolls up its traffic here."
        />
      </div>
    )
  }

  return <UsageView pkg={packageRow} />
}

function UsageView({ pkg }: { pkg: Package }) {
  const { theme } = useAppTheme()
  const toast = useToast()
  const [granularity, setGranularity] = useState(0) // 0 = hour, 1 = day
  const period = granularity === 0 ? 'hour' : 'day'
  const usage = useLoad(() => {
    const windowMs = granularity === 0 ? 48 * 3_600_000 : 30 * 86_400_000
    const from = new Date(Date.now() - windowMs).toISOString()
    return packagesApi.usage(pkg.name, { period, from }).then((res) => rollupsToUsageSeries(res.data ?? []))
  }, [pkg.name, granularity, period])

  const series = usage.data
  const hasData = !!series && series.requests.length > 0
  const kpis = series?.kpis
  const endpoints = series?.topEndpoints ?? []

  const reqEOption = useMemo(() => timeSeriesOption(series?.requests ?? [], { theme, type: 'line', area: true, scale: { requests: '#6929c4' }, showX: true }), [series, theme])
  const errEOption = useMemo(() => timeSeriesOption(series?.errorRate ?? [], { theme, type: 'line', scale: { '4xx': '#d2a106', '5xx': '#fa4d56' }, yPercent: true, showX: true }), [series, theme])
  const latEOption = useMemo(() => timeSeriesOption(series?.latency ?? [], { theme, type: 'line', scale: { avg: '#1192e8', p95: '#9f1853' }, showX: true }), [series, theme])
  const txEOption = useMemo(() => timeSeriesOption(series?.transfer ?? [], { theme, type: 'line', area: true, stack: true, scale: { in: '#005d5d', out: '#6929c4' }, showX: true }), [series, theme])

  const exportEndpoints = () => {
    const filename = `${pkg.slug ?? pkg.name}-endpoints.csv`
    exportCsv(filename, [
      ['route', 'method', 'requests', 'error_rate', 'p95_ms'],
      ...endpoints.map((e) => [e.route, e.method, e.requests, e.error_rate, e.p95_ms]),
    ])
    toast.success('Exported CSV', filename)
  }

  const dash = (v: string | undefined) => (hasData && v !== undefined ? v : '—')

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
      {/* Controls */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' }}>
        <ContentSwitcher selectedIndex={granularity} onChange={({ index }) => setGranularity(index ?? 0)} size="sm" style={{ width: 160 }}>
          <Switch name="hour" text="Hour" />
          <Switch name="day" text="Day" />
        </ContentSwitcher>
        <div
          className="scns-mono"
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            height: 32,
            padding: '0 12px',
            border: '1px solid var(--cds-border-strong)',
            fontSize: 12,
          }}
        >
          <Calendar size={16} />
          {granularity === 0 ? 'last 48h' : 'last 30d'}
        </div>
        <span className="scns-helper" style={{ fontSize: 12 }}>
          UTC · rollup per {period} · in-process metering
        </span>
        <div style={{ flex: 1 }} />
        <Button kind="tertiary" size="md" renderIcon={Download} onClick={exportEndpoints} disabled={!hasData}>
          Export CSV
        </Button>
      </div>

      {/* KPI strip */}
      <KpiCluster columns={6}>
        <KpiTile label="Requests" value={dash(kpis && formatCompact(kpis.totalRequests))} valueSize={22} />
        <KpiTile label="Error rate" value={dash(kpis && formatPercent(kpis.errorRate, 1))} valueSize={22} />
        <KpiTile label="Avg latency" value={dash(kpis && `${kpis.avgLatencyMs}ms`)} valueSize={22} />
        <KpiTile label="p95" value={dash(kpis && `${kpis.p95Ms}ms`)} valueSize={22} />
        <KpiTile label="Unique users" value={dash(kpis && formatInt(kpis.uniqueUsers))} valueSize={22} />
        <KpiTile label="Bytes (in / out)" value={dash(kpis && `${formatBytes(kpis.bytesIn)} / ${formatBytes(kpis.bytesOut)}`)} valueSize={22} />
      </KpiCluster>

      {usage.loading ? (
        <div style={{ display: 'flex', justifyContent: 'center', padding: '64px 0' }}>
          <Loading withOverlay={false} description="Loading usage…" />
        </div>
      ) : usage.error ? (
        <div className="scns-card">
          <EmptyState
            title="Usage unavailable"
            description={`Could not load usage: ${usage.error}`}
          />
        </div>
      ) : !hasData ? (
        <div className="scns-card">
          <EmptyState
            title="No usage data yet"
            description="This deploy is freshly provisioned or has no traffic in the selected window. Metering rolls up hourly."
          />
        </div>
      ) : (
        <>
          {/* Charts 2×2 */}
          <div className="scns-grid-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 24 }}>
            <ChartCard title="Requests" subtitle={`per ${period}`}>
              <EChart option={reqEOption} height={240} />
            </ChartCard>
            <ChartCard
              title="Error rate"
              subtitle="%"
              right={<ChartLegend items={[{ label: '4xx', color: '#d2a106' }, { label: '5xx', color: '#fa4d56' }]} />}
            >
              <EChart option={errEOption} height={240} />
            </ChartCard>
            <ChartCard
              title="Latency"
              subtitle="ms"
              right={<ChartLegend items={[{ label: 'avg', color: '#1192e8' }, { label: 'p95', color: '#9f1853' }]} />}
            >
              <EChart option={latEOption} height={240} />
            </ChartCard>
            <ChartCard
              title="Transfer"
              subtitle="MB"
              right={<ChartLegend items={[{ label: 'in', color: '#005d5d' }, { label: 'out', color: '#6929c4' }]} />}
            >
              <EChart option={txEOption} height={240} />
            </ChartCard>
          </div>

          {/* Top endpoints */}
          <div className="scns-card">
            <div style={{ padding: '16px 16px 8px', fontSize: 16, fontWeight: 600 }}>Top endpoints</div>
            <TableContainer>
              <Table size="sm">
                <TableHead>
                  <TableRow>
                    <TableHeader>Route</TableHeader>
                    <TableHeader>Method</TableHeader>
                    <TableHeader style={{ textAlign: 'right' }}>Requests</TableHeader>
                    <TableHeader style={{ textAlign: 'right' }}>Error %</TableHeader>
                    <TableHeader style={{ textAlign: 'right' }}>p95</TableHeader>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {endpoints.map((e) => {
                    const anomalous = e.error_rate > 0.03
                    return (
                      <TableRow key={`${e.method} ${e.route}`} style={anomalous ? { background: 'var(--scns-amber-bg)' } : undefined}>
                        <TableCell className="scns-mono">{e.route}</TableCell>
                        <TableCell>
                          <MethodTag method={e.method} />
                        </TableCell>
                        <TableCell style={{ textAlign: 'right' }} className="scns-num">
                          {formatInt(e.requests)}
                        </TableCell>
                        <TableCell style={{ textAlign: 'right' }} className="scns-num">
                          {formatPercent(e.error_rate, 1)}
                        </TableCell>
                        <TableCell style={{ textAlign: 'right' }} className="scns-num">
                          {e.p95_ms}ms
                        </TableCell>
                      </TableRow>
                    )
                  })}
                </TableBody>
              </Table>
            </TableContainer>
          </div>
        </>
      )}
    </div>
  )
}
