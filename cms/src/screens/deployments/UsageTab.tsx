import { useState } from 'react'
import {
  Button,
  ContentSwitcher,
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
import {
  Card,
  KpiTile,
  MethodTag,
  Mono,
  Num,
} from '../../components/ui'
import { ChartLegend, ScArea, ScLine } from '../../components/charts'
import {
  USAGE_ERROR_COLORS,
  USAGE_LATENCY_COLORS,
  USAGE_REQUESTS_COLORS,
  USAGE_TRANSFER_COLORS,
  usageErrorRate,
  usageLatency,
  usageRequests,
  usageTransfer,
} from '../../data/charts'
import { fmtBigBytes, fmtCompact, fmtInt } from '../../lib/format'
import { getUsageSummary } from '../../data/dataset'
import { useToast } from '../../notify/ToastProvider'

const AMBER_BG = 'var(--sc-tag-amber-bg)'

export function UsageTab({ deploymentId }: { deploymentId: string }) {
  const toast = useToast()
  const u = getUsageSummary(deploymentId)
  const [granularity, setGranularity] = useState(0)

  const endpoints = [...u.topEndpoints].sort((a, b) => b.requests - a.requests)

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
      <div
        style={{
          display: 'flex',
          justifyContent: 'flex-end',
        }}
      >
        <Button
          kind="tertiary"
          size="sm"
          renderIcon={Download}
          onClick={() =>
            toast.success('Export started', 'CSV is being generated.')
          }
        >
          Export CSV
        </Button>
      </div>

      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: 16,
          flexWrap: 'wrap',
        }}
      >
        <ContentSwitcher
          size="sm"
          selectedIndex={granularity}
          onChange={({ index }) => setGranularity(index ?? 0)}
          style={{ maxWidth: 160 }}
        >
          <Switch text="Hour" />
          <Switch text="Day" />
        </ContentSwitcher>

        <span
          style={{
            display: 'inline-flex',
            alignItems: 'center',
            gap: 6,
            height: 32,
            padding: '0 10px',
            border: '1px solid var(--cds-border-subtle)',
            background: 'var(--cds-layer)',
          }}
        >
          <Calendar />
          <Mono size={12}>Jun 04 → Jun 11, 2026</Mono>
        </span>

        <span style={{ fontSize: 12, color: 'var(--cds-text-helper)' }}>
          UTC · rollup per hour
        </span>
      </div>

      <div
        className="sc-tile-cluster"
        style={{ gridTemplateColumns: 'repeat(6, 1fr)' }}
      >
        <KpiTile label="Requests (7d)" value={fmtCompact(u.requests7d)} />
        <KpiTile label="Error rate" value={`${u.errorPct}%`} />
        <KpiTile label="Avg latency" value={`${u.avgLatencyMs}ms`} />
        <KpiTile label="p95" value={`${u.p95Ms}ms`} />
        <KpiTile label="Unique users" value={fmtInt(u.uniqueUsers)} />
        <KpiTile
          label="Bytes"
          value={`${fmtBigBytes(u.bytesUsed)} / ${fmtBigBytes(u.bytesQuota)}`}
        />
      </div>

      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(2, 1fr)',
          gap: 16,
        }}
      >
        <Card
          title="Requests"
          right={
            <ChartLegend items={[{ label: 'requests', color: '#6929c4' }]} />
          }
        >
          <ScArea
            data={usageRequests}
            colors={USAGE_REQUESTS_COLORS}
            leftFormatter={(v) => fmtCompact(v)}
          />
        </Card>

        <Card
          title="Error rate"
          right={
            <ChartLegend
              items={[
                { label: '4xx', color: '#d2a106' },
                { label: '5xx', color: '#fa4d56' },
              ]}
            />
          }
        >
          <ScLine
            data={usageErrorRate}
            colors={USAGE_ERROR_COLORS}
            leftFormatter={(v) => v.toFixed(1) + '%'}
          />
        </Card>

        <Card
          title="Latency ms"
          right={
            <ChartLegend
              items={[
                { label: 'avg', color: '#1192e8' },
                { label: 'p95', color: '#9f1853', dashed: true },
              ]}
            />
          }
        >
          <ScLine
            data={usageLatency}
            colors={USAGE_LATENCY_COLORS}
            leftFormatter={(v) => Math.round(v) + ''}
          />
        </Card>

        <Card
          title="Transfer MB/h"
          right={
            <ChartLegend
              items={[
                { label: 'in', color: '#005d5d' },
                { label: 'out', color: '#6929c4' },
              ]}
            />
          }
        >
          <ScLine
            data={usageTransfer}
            colors={USAGE_TRANSFER_COLORS}
            leftFormatter={(v) => Math.round(v) + 'MB'}
          />
        </Card>
      </div>

      <Card
        title="Top endpoints"
        right={
          <span style={{ fontSize: 12, color: 'var(--cds-text-helper)' }}>
            last 7 days · sorted by requests
          </span>
        }
        bodyStyle={{ padding: 0 }}
      >
        <TableContainer>
          <Table size="sm">
            <TableHead>
              <TableRow>
                <TableHeader>Route</TableHeader>
                <TableHeader>Method</TableHeader>
                <TableHeader style={{ textAlign: 'right' }}>
                  Requests
                </TableHeader>
                <TableHeader style={{ textAlign: 'right' }}>Error %</TableHeader>
                <TableHeader style={{ textAlign: 'right' }}>
                  p95 (ms)
                </TableHeader>
              </TableRow>
            </TableHead>
            <TableBody>
              {endpoints.map((ep) => {
                const cellBg = ep.anomalous ? AMBER_BG : undefined
                return (
                  <TableRow key={`${ep.method} ${ep.route}`}>
                    <TableCell style={{ background: cellBg }}>
                      <Mono size={12}>{ep.route}</Mono>
                    </TableCell>
                    <TableCell style={{ background: cellBg }}>
                      <MethodTag method={ep.method} />
                    </TableCell>
                    <TableCell style={{ background: cellBg }}>
                      <Num>{fmtInt(ep.requests)}</Num>
                    </TableCell>
                    <TableCell style={{ background: cellBg }}>
                      <Num
                        color={
                          ep.anomalous
                            ? 'var(--sc-support-warning)'
                            : undefined
                        }
                      >
                        {ep.errorPct.toFixed(1)}%
                      </Num>
                    </TableCell>
                    <TableCell style={{ background: cellBg }}>
                      <Num>{ep.p95Ms}</Num>
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </TableContainer>
      </Card>
    </div>
  )
}
