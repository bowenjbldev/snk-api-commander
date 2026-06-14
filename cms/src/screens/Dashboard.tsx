import type { ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { Tag } from '@carbon/react'

import {
  PageHeader,
  KpiTile,
  Card,
  Mono,
  SeverityPill,
  StatusDot,
  RelativeTime,
} from '../components/ui'
import { ScStackedBar, ScArea, ChartLegend } from '../components/charts'
import {
  requests48hStacked,
  REQUESTS_48H_COLORS,
  REQUESTS_48H_GROUPS,
  errorRate48h,
  ERROR_RATE_COLORS,
  ERROR_RATE_PEAK,
} from '../data/charts'
import { dashboardKpis, attention } from '../data/dataset'
import { useStore } from '../store/DataStore'
import { useNowMs } from '../lib/time'
import { clockMs, countdownShort, fmtCompact } from '../lib/format'
import type { ActivityKind, ActivitySegment } from '../types'

// --- local helpers ---------------------------------------------------------

/** Weekday + date label, e.g. "Wednesday, Jun 11 2026", in UTC. */
const WEEKDAYS = [
  'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday',
]
const MONTHS = [
  'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
]
function weekdayDate(iso: string): string {
  const d = new Date(iso)
  return `${WEEKDAYS[d.getUTCDay()]}, ${MONTHS[d.getUTCMonth()]} ${d.getUTCDate()} ${d.getUTCFullYear()}`
}

/** Activity dot tone keyed by event kind. */
const KIND_TONE: Record<ActivityKind, 'green' | 'amber' | 'red'> = {
  suspend: 'red',
  grace: 'amber',
  paid: 'green',
  quota: 'amber',
  resume: 'green',
}

/** Route for a linked activity segment entity. */
function segmentHref(link: NonNullable<ActivitySegment['link']>): string {
  switch (link.kind) {
    case 'deployment':
      return `/deployments/${link.id}`
    case 'invoice':
      return `/invoices?focus=${link.id}`
    case 'client':
      return `/clients?focus=${link.id}`
  }
}

export function Dashboard() {
  const navigate = useNavigate()
  const nowMs = useNowMs()
  const { deployments, activity } = useStore()

  const activeCount = deployments.filter((d) => d.state.status === 'active').length
  const graceCount = deployments.filter((d) => d.state.status === 'grace').length
  const suspendedCount = deployments.filter((d) => d.state.status === 'suspended').length
  const totalCount = deployments.length

  const graceRemainders = deployments
    .filter((d) => d.state.status === 'grace' && d.state.graceUntil)
    .map((d) => Date.parse(d.state.graceUntil as string) - nowMs)
  const minGraceRemaining = graceRemainders.length
    ? Math.min(...graceRemainders)
    : dashboardKpis.nextDeadlineMs

  const renderSegment = (seg: ActivitySegment, i: number): ReactNode => {
    const style = seg.strong ? { fontWeight: 600 } : undefined
    if (seg.link) {
      const href = segmentHref(seg.link)
      return (
        <Mono key={i} color="var(--cds-link-primary)">
          <span
            style={{ cursor: 'pointer', ...style }}
            onClick={() => navigate(href)}
          >
            {seg.text}
          </span>
        </Mono>
      )
    }
    if (seg.mono) {
      return (
        <Mono key={i}>
          <span style={style}>{seg.text}</span>
        </Mono>
      )
    }
    return (
      <span key={i} style={style}>
        {seg.text}
      </span>
    )
  }

  return (
    <div className="sc-page">
      <PageHeader
        title="Dashboard"
        subtitle={weekdayDate(dashboardKpis.updatedAt)}
        right={
          <span className="sc-mono" style={{ fontSize: 12, color: 'var(--cds-text-helper)' }}>
            updated {clockMs(dashboardKpis.updatedAt)} UTC · auto-refresh 30s
          </span>
        }
      />

      {/* 2) KPI ROW */}
      <div
        className="sc-tile-cluster"
        style={{ gridTemplateColumns: 'repeat(6,1fr)', marginBottom: 24 }}
      >
        <KpiTile
          label="Active deployments"
          value={activeCount}
          helper={`of ${totalCount} total`}
        />
        <KpiTile
          label="In grace"
          value={graceCount}
          valueColor="var(--sc-support-warning)"
          helper={`next deadline ${countdownShort(minGraceRemaining)}`}
        />
        <KpiTile
          label="Suspended"
          value={suspendedCount}
          valueColor="var(--sc-support-error)"
          helper={dashboardKpis.suspendedBreakdown}
        />
        <KpiTile
          label="Requests (24h)"
          value={dashboardKpis.requests24h}
          helper={dashboardKpis.requests24hDelta}
          helperColor="var(--sc-support-success)"
        />
        <KpiTile
          label="Error rate (24h)"
          value={dashboardKpis.errorRate}
          helper={dashboardKpis.errorRateDelta}
          helperColor="var(--sc-support-error)"
        />
        <KpiTile
          label="MRR"
          value={dashboardKpis.mrr}
          helper={dashboardKpis.mrrDelta}
          helperColor="var(--sc-support-success)"
        />
      </div>

      {/* 3) ATTENTION REQUIRED */}
      <div style={{ marginBottom: 24 }}>
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            marginBottom: 8,
          }}
        >
          <span className="sc-section-label" style={{ fontSize: 16 }}>
            Attention required
          </span>
          <Tag type="red">{attention.length}</Tag>
        </div>
        <div className="sc-list">
          {attention.map((item) => (
            <div key={item.id} className="sc-list-row" style={{ height: 48 }}>
              <SeverityPill severity={item.severity} />
              <Mono color="var(--cds-link-primary)">
                <span
                  style={{ cursor: 'pointer' }}
                  onClick={() => navigate(item.to)}
                >
                  {item.entity}
                </span>
              </Mono>
              <span
                style={{
                  flex: 1,
                  minWidth: 0,
                  fontSize: 13,
                  color: 'var(--cds-text-secondary)',
                }}
              >
                {item.description}
              </span>
              <span
                style={{
                  color: 'var(--cds-link-primary)',
                  cursor: 'pointer',
                  fontSize: 13,
                  whiteSpace: 'nowrap',
                  flex: 'none',
                }}
                onClick={() => navigate(item.to)}
              >
                {item.deepLinkLabel}
              </span>
            </div>
          ))}
        </div>
      </div>

      {/* 4) CHARTS ROW */}
      <div
        style={{
          display: 'grid',
          gridTemplateColumns: '2fr 1fr',
          gap: 16,
          marginBottom: 24,
        }}
      >
        <Card
          title={
            <span style={{ display: 'flex', alignItems: 'baseline', gap: 8 }}>
              Requests per hour
              <span style={{ fontSize: 12, fontWeight: 400, color: 'var(--cds-text-helper)' }}>
                last 48h · stacked by deployment
              </span>
            </span>
          }
          right={
            <ChartLegend
              items={REQUESTS_48H_GROUPS.map((g) => ({
                label: g,
                color: REQUESTS_48H_COLORS[g],
              }))}
            />
          }
        >
          <ScStackedBar
            data={requests48hStacked}
            colors={REQUESTS_48H_COLORS}
            leftFormatter={(v) => fmtCompact(v)}
          />
        </Card>

        <Card
          title={
            <span style={{ display: 'flex', alignItems: 'baseline', gap: 8 }}>
              Error rate
              <span style={{ fontSize: 12, fontWeight: 400, color: 'var(--cds-text-helper)' }}>
                4xx + 5xx · {ERROR_RATE_PEAK}
              </span>
            </span>
          }
        >
          <ScArea
            data={errorRate48h}
            colors={ERROR_RATE_COLORS}
            leftFormatter={(v) => v.toFixed(1) + '%'}
          />
        </Card>
      </div>

      {/* 5) RECENT ACTIVITY */}
      <div>
        <div
          style={{
            display: 'flex',
            alignItems: 'baseline',
            gap: 8,
            marginBottom: 8,
          }}
        >
          <span className="sc-section-label" style={{ fontSize: 16 }}>
            Recent activity
          </span>
          <span style={{ fontSize: 12, color: 'var(--cds-text-helper)' }}>
            state changes, last 3 days
          </span>
        </div>
        <div className="sc-list">
          {activity.map((ev) => (
            <div key={ev.id} className="sc-list-row" style={{ height: 44 }}>
              <StatusDot tone={KIND_TONE[ev.kind]} />
              <span
                style={{
                  flex: 1,
                  minWidth: 0,
                  fontSize: 13,
                  color: 'var(--cds-text-secondary)',
                }}
              >
                {ev.parts.map(renderSegment)}
              </span>
              <span style={{ flex: 'none', fontSize: 12 }}>
                <RelativeTime iso={ev.at} mono />
              </span>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
