import { useNavigate } from 'react-router-dom'
import { Calendar } from '@carbon/icons-react'
import {
  Card,
  DataGrid,
  KpiTile,
  Mono,
  Num,
  PageHeader,
  type GridColumn,
} from '../components/ui'
import { ChartLegend, ScArea, ScStackedBar } from '../components/charts'
import {
  ERROR_RATE_COLORS,
  ERROR_RATE_PEAK,
  errorRate48h,
  REQUESTS_48H_COLORS,
  REQUESTS_48H_GROUPS,
  requests48hStacked,
} from '../data/charts'
import { dashboardKpis } from '../data/dataset'
import { useStore } from '../store/DataStore'
import { fmtCompact, fmtInt } from '../lib/format'
import type { Deployment } from '../types'

export function Usage() {
  const navigate = useNavigate()
  const { deployments } = useStore()
  const totalReq = deployments.reduce((s, d) => s + d.req24h, 0)

  const columns: GridColumn<Deployment>[] = [
    {
      key: 'name',
      header: 'Deployment',
      sortValue: (d) => d.name,
      render: (d) => <Mono color="var(--cds-link-primary)">{d.name}</Mono>,
    },
    {
      key: 'client',
      header: 'Client',
      sortValue: (d) => d.clientName,
      render: (d) => d.clientName,
    },
    {
      key: 'req',
      header: 'Requests (24h)',
      align: 'right',
      sortValue: (d) => d.req24h,
      render: (d) => <Num>{fmtInt(d.req24h)}</Num>,
    },
    {
      key: 'quota',
      header: 'Quota health',
      align: 'right',
      sortValue: (d) => d.quotaHealth,
      render: (d) => (
        <Num
          color={
            d.quotaHealth > 1
              ? 'var(--sc-support-error)'
              : d.quotaHealth >= 0.8
                ? 'var(--sc-support-warning)'
                : undefined
          }
        >
          {Math.round(d.quotaHealth * 100)}%
        </Num>
      ),
    },
  ]

  return (
    <div className="sc-page">
      <PageHeader
        title="Usage"
        subtitle="Fleet-wide traffic · UTC · rollup per hour"
        right={
          <span
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: 8,
              border: '1px solid var(--cds-border-strong)',
              padding: '6px 10px',
            }}
          >
            <Calendar size={16} />
            <Mono size={12}>Jun 09 → Jun 11, 2026</Mono>
          </span>
        }
      />

      <div
        className="sc-tile-cluster"
        style={{ gridTemplateColumns: 'repeat(4,1fr)', marginBottom: 24 }}
      >
        <KpiTile label="Requests (24h)" value={dashboardKpis.requests24h} helper={dashboardKpis.requests24hDelta} helperColor="var(--sc-support-success)" />
        <KpiTile label="Error rate (24h)" value={dashboardKpis.errorRate} helper={dashboardKpis.errorRateDelta} helperColor="var(--sc-support-error)" />
        <KpiTile label="Active deployments" value={deployments.filter((d) => d.state.status === 'active').length} helper={`of ${deployments.length} total`} />
        <KpiTile label="Sum req (table)" value={fmtCompact(totalReq)} helper="across all deployments" />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 16, marginBottom: 24 }}>
        <Card
          title="Requests per hour"
          right={
            <ChartLegend
              items={REQUESTS_48H_GROUPS.map((g) => ({ label: g, color: REQUESTS_48H_COLORS[g] }))}
            />
          }
        >
          <div style={{ fontSize: 12, color: 'var(--cds-text-helper)', marginBottom: 8 }}>
            last 48h · stacked by deployment
          </div>
          <ScStackedBar data={requests48hStacked} colors={REQUESTS_48H_COLORS} leftFormatter={(v) => fmtCompact(v)} />
        </Card>
        <Card title="Error rate">
          <div style={{ fontSize: 12, color: 'var(--cds-text-helper)', marginBottom: 8 }}>
            4xx + 5xx · {ERROR_RATE_PEAK}
          </div>
          <ScArea data={errorRate48h} colors={ERROR_RATE_COLORS} leftFormatter={(v) => v.toFixed(1) + '%'} />
        </Card>
      </div>

      <DataGrid
        rows={deployments}
        getRowId={(d) => d.id}
        columns={columns}
        searchText={(d) => `${d.name} ${d.clientName}`}
        searchPlaceholder="Search deployments…"
        initialSort={{ key: 'req', dir: 'DESC' }}
        onRowClick={(d) => navigate('/deployments/' + d.id)}
      />
    </div>
  )
}
