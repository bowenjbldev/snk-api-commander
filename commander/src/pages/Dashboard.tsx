import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router'
import { Tag, Loading } from '@carbon/react'
import { StackedBarChart, AreaChart } from '@carbon/charts-react'
import { useStore } from '../store/store'
import { useAppTheme } from '../providers/ThemeProvider'
import { useTick } from '../providers/TickProvider'
import { KpiCluster, KpiTile } from '../components/KpiCluster'
import { ChartCard, ChartLegend } from '../components/ChartCard'
import { GraceCountdown } from '../components/GraceCountdown'
import { RelativeTime } from '../components/RelativeTime'
import { EmptyState } from '../components/EmptyState'
import { stackedBarOptions, areaOptions, legendItems } from '../lib/chartOptions'
import { snapshotsToPlatformSeries, type PlatformSeries } from '../lib/chartData'
import { platformUsage } from '../lib/api'
import { formatCompact, formatCurrencyShort, formatCurrency, formatPercent } from '../lib/format'
import type { ActivityEvent } from '../store/types'

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
  const deployments = useStore((s) => s.deployments)
  const invoices = useStore((s) => s.invoices)
  const quotas = useStore((s) => s.quotas)
  const activity = useStore((s) => s.activity)
  const dash = useStore((s) => s.dashboard)

  const counts = useMemo(() => {
    let active = 0, grace = 0, suspended = 0
    for (const d of deployments) {
      if (d.state.status === 'active') active++
      else if (d.state.status === 'grace') grace++
      else suspended++
    }
    return { active, grace, suspended }
  }, [deployments])

  // Real platform usage from the Commander snapshot cache (seeded + refreshed by deployment sync).
  const [platform, setPlatform] = useState<{ loading: boolean; series: PlatformSeries | null; error: string | null }>({
    loading: true,
    series: null,
    error: null,
  })
  useEffect(() => {
    let cancelled = false
    const load = () => {
      platformUsage({ period: 'hour', hours: 48 })
        .then((res) => {
          if (!cancelled) setPlatform({ loading: false, series: snapshotsToPlatformSeries(res.data ?? []), error: null })
        })
        .catch((e: unknown) => {
          // Keep the last good series on a refresh error so the chart doesn't blank out.
          if (!cancelled) setPlatform((p) => ({ loading: false, series: p.series, error: e instanceof Error ? e.message : 'Failed to load usage' }))
        })
    }
    load()
    const timer = setInterval(load, 30_000) // honours the "auto-refresh 30s" label
    return () => {
      cancelled = true
      clearInterval(timer)
    }
  }, [])

  const groups = useMemo(() => platform.series?.requests.groups ?? [], [platform.series])
  const reqData = platform.series?.requests.data ?? []
  const errData = platform.series?.errorRate ?? []
  const hasChart = !platform.loading && !platform.error && reqData.length > 0
  const reqOpts = useMemo(() => stackedBarOptions(theme, groups, '320px'), [theme, groups])
  const errOpts = useMemo(() => areaOptions(theme, { 'error %': '#fa4d56' }, '320px'), [theme])

  const attention = useMemo(() => {
    const items: { tone: 'red' | 'amber'; sev: string; entity: string; desc: ReactNode; cta: string; to: string }[] = []
    for (const q of quotas) {
      if (q.limit_value != null && q.current_value / q.limit_value > 1) {
        items.push({
          tone: 'red', sev: 'QUOTA OVER', entity: q.deployment_id,
          desc: <>{q.metric_key} at {formatPercent(q.current_value / q.limit_value)} of limit</>,
          cta: 'Review quota →', to: '/quotas',
        })
      }
    }
    for (const d of deployments) {
      if (d.state.status === 'grace' && d.state.grace_until) {
        items.push({
          tone: 'amber', sev: 'GRACE ENDS', entity: d.name,
          desc: <>suspends in <GraceCountdown until={d.state.grace_until} /> · {d.state.reason}</>,
          cta: 'Open deployment →', to: `/deployments/${d.id}`,
        })
      }
    }
    for (const i of invoices) {
      if (i.status === 'overdue') {
        items.push({
          tone: 'red', sev: 'OVERDUE', entity: i.id,
          desc: <>{formatCurrency(i.amount)} — linked {i.deployment_id} {i.enforcement === 'suspended' ? 'suspended' : 'in grace'}</>,
          cta: 'Open invoice →', to: '/invoices',
        })
      }
    }
    return items
  }, [quotas, deployments, invoices])

  return (
    <div className="scns-page">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 24 }}>
        <div>
          <h1 style={{ fontSize: 24, fontWeight: 400, margin: 0 }}>Dashboard</h1>
          <div className="scns-helper" style={{ fontSize: 13, marginTop: 6 }}>
            Platform overview across {deployments.length} deployments
          </div>
        </div>
        <UpdatedClock />
      </div>

      {/* KPI row */}
      <KpiCluster columns={6}>
        <KpiTile label="Active" value={counts.active} helper={`of ${deployments.length}`} />
        <KpiTile label="In grace" value={counts.grace} valueClass="scns-val-amber" helper="awaiting payment" />
        <KpiTile label="Suspended" value={counts.suspended} valueClass="scns-val-red" helper="serving HTTP 402" />
        <KpiTile label="Requests (24h)" value={formatCompact(dash.requests_24h)} helper={<span className="scns-val-green">▲ {formatPercent(dash.requests_delta)} vs prior 24h</span>} />
        <KpiTile label="Error rate (24h)" value={formatPercent(dash.error_rate, 1)} helper={<span className="scns-val-red">▲ {formatPercent(dash.error_rate_delta, 1)}</span>} />
        <KpiTile label="MRR" value={formatCurrencyShort(dash.mrr)} helper={<span className="scns-val-green">▲ {formatCurrencyShort(dash.mrr_delta)} this month</span>} />
      </KpiCluster>

      {/* Attention required */}
      <div style={{ marginTop: 32 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
          <span style={{ fontSize: 16, fontWeight: 600 }}>Attention required</span>
          <Tag type="red" size="sm">{attention.length}</Tag>
        </div>
        <div className="scns-card">
          {attention.length === 0 ? (
            <EmptyState title="No issues detected" />
          ) : (
            attention.map((a, idx) => (
              <button
                key={idx}
                type="button"
                onClick={() => navigate(a.to)}
                style={{
                  display: 'flex', alignItems: 'center', gap: 16, width: '100%', height: 48, padding: '0 16px',
                  background: 'none', border: 'none', borderTop: idx ? '1px solid var(--cds-border-subtle)' : 'none',
                  cursor: 'pointer', textAlign: 'left',
                }}
              >
                <Pill tone={a.tone}>{a.sev}</Pill>
                <span className="scns-mono" style={{ color: 'var(--cds-link-primary)', minWidth: 140 }}>{a.entity}</span>
                <span style={{ flex: 1, fontSize: 13, color: 'var(--cds-text-secondary)' }}>{a.desc}</span>
                <span className="scns-link" style={{ fontSize: 13 }}>{a.cta}</span>
              </button>
            ))
          )}
        </div>
      </div>

      {/* Charts row */}
      <div className="scns-charts-row" style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 24, marginTop: 32 }}>
        <ChartCard
          title="Requests per hour"
          subtitle="last 48h, stacked by deployment"
          right={<ChartLegend items={legendItems(groups)} />}
        >
          {hasChart ? (
            <StackedBarChart data={reqData} options={reqOpts} />
          ) : (
            <ChartPlaceholder loading={platform.loading} error={platform.error} height={320} />
          )}
        </ChartCard>
        <ChartCard
          title="Error rate"
          subtitle="%"
          right={<ChartLegend items={[{ label: '4xx', color: '#d2a106' }, { label: '5xx', color: '#fa4d56' }]} />}
        >
          {hasChart ? (
            <AreaChart data={errData} options={errOpts} />
          ) : (
            <ChartPlaceholder loading={platform.loading} error={platform.error} height={320} />
          )}
        </ChartCard>
      </div>

      {/* Recent activity */}
      <div style={{ marginTop: 32 }}>
        <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, marginBottom: 12 }}>
          <span style={{ fontSize: 16, fontWeight: 600 }}>Recent activity</span>
          <span className="scns-helper" style={{ fontSize: 13 }}>
            state changes, last 3 days
          </span>
        </div>
        <div className="scns-card">
          {activity.length === 0 ? (
            <EmptyState title="No recent activity" />
          ) : (
            activity.map((ev, idx) => (
              <div
                key={ev.id}
                style={{
                  display: 'flex', alignItems: 'center', gap: 12, height: 44, padding: '0 16px',
                  borderTop: idx ? '1px solid var(--cds-border-subtle)' : 'none',
                }}
              >
                <span style={{ width: 8, height: 8, borderRadius: '50%', background: dotColor(ev.kind), flex: 'none' }} aria-hidden="true" />
                <span style={{ flex: 1, fontSize: 13 }}>{activitySentence(ev)}</span>
                <RelativeTime iso={ev.at} />
              </div>
            ))
          )}
        </div>
      </div>
    </div>
  )
}

function dotColor(kind: ActivityEvent['kind']): string {
  if (kind === 'suspend') return 'var(--scns-error)'
  if (kind === 'grace' || kind === 'quota') return 'var(--scns-warning)'
  return 'var(--scns-success)'
}

function activitySentence(ev: ActivityEvent): ReactNode {
  const ent = (s?: string) => <span className="scns-mono" style={{ color: 'var(--cds-link-primary)' }}>{s}</span>
  switch (ev.kind) {
    case 'suspend':
      return <>{ent(ev.deployment_id)} suspended — {ev.reason} · by {ev.by}</>
    case 'grace':
      return <>{ent(ev.deployment_id)} moved to grace — {ev.reason} · by {ev.by}</>
    case 'resume':
      return <>{ent(ev.deployment_id)} resumed · by {ev.by}</>
    case 'paid':
      return <>{ent(ev.invoice_id)} marked paid{ev.message ? ` — ${ev.message}` : ''}</>
    case 'quota':
      return <>{ent(ev.deployment_id)} {ev.message}</>
    case 'rotate':
      return <>{ent(ev.deployment_id)} control secret rotated{ev.message ? ` → ${ev.message}` : ''} · by {ev.by}</>
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

function Pill({ tone, children }: { tone: 'red' | 'amber'; children: ReactNode }) {
  const bg = tone === 'red' ? 'var(--scns-tag-red-bg)' : 'var(--scns-amber-bg)'
  const color = tone === 'red' ? 'var(--scns-tag-red-text)' : 'var(--scns-amber-text)'
  return (
    <span
      className="scns-mono"
      style={{
        fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.4, background: bg, color,
        padding: '3px 8px', borderRadius: 24, flex: 'none', minWidth: 92, textAlign: 'center',
      }}
    >
      {children}
    </span>
  )
}
