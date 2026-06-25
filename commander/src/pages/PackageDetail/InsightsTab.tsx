import { useEffect, useMemo, useState } from 'react'
import { useOutletContext } from 'react-router'
import {
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
  Tag,
} from '@carbon/react'
import type { Package, PackageContext, PackageInsights } from '../../store/types'
import { useAppTheme } from '../../providers/ThemeProvider'
import { ChartCard, ChartLegend } from '../../components/ChartCard'
import { EChart } from '../../components/EChart'
import { EmptyState } from '../../components/EmptyState'
import { KpiCluster, KpiTile } from '../../components/KpiCluster'
import { CopyableCode } from '../../components/CopyableCode'
import { timeSeriesOption, heatmapOption } from '../../lib/echartsOptions'
import { dailyToCodeFrequency, dailyToHeatmap } from '../../lib/chartData'
import { packagesApi } from '../../lib/api'
import { formatCompact, formatInt } from '../../lib/format'
import { formatDateUTC, formatRelative } from '../../lib/time'

const RANGES = [
  { label: '3 mo', days: 91 },
  { label: '6 mo', days: 182 },
  { label: '12 mo', days: 364 },
]

interface InsightsLoad {
  loading: boolean
  data: PackageInsights | null
  error: string | null
}

export function InsightsTab() {
  const { packageRow } = useOutletContext<PackageContext>()

  if (!packageRow) {
    return (
      <div className="scns-card">
        <EmptyState
          title="No live instance yet — provision one to see insights"
          description="Git insights are synced per provisioned package by the commander:git:sync command."
        />
      </div>
    )
  }

  return <InsightsView pkg={packageRow} />
}

function InsightsView({ pkg }: { pkg: Package }) {
  const { theme } = useAppTheme()
  const [rangeIdx, setRangeIdx] = useState(2) // default 12 mo
  const [state, setState] = useState<InsightsLoad>({ loading: true, data: null, error: null })

  const days = RANGES[rangeIdx].days

  useEffect(() => {
    let cancelled = false
    setState({ loading: true, data: null, error: null })
    packagesApi.insights(pkg.name, { days })
      .then((res) => {
        if (!cancelled) setState({ loading: false, data: res.data, error: null })
      })
      .catch((e: unknown) => {
        if (!cancelled) setState({ loading: false, data: null, error: e instanceof Error ? e.message : 'Failed to load insights' })
      })
    return () => {
      cancelled = true
    }
  }, [pkg.name, days])

  const insights = state.data
  const daily = insights?.daily ?? []
  const summary = insights?.summary
  const repo = insights?.repo
  const hasHistory = (summary?.total_commits ?? 0) > 0

  const heatData = useMemo(() => dailyToHeatmap(daily, days), [daily, days])
  const heatEOption = useMemo(() => heatmapOption(heatData, theme), [heatData, theme])
  const freqData = useMemo(() => dailyToCodeFrequency(daily), [daily])
  const freqEOption = useMemo(() => timeSeriesOption(freqData, { theme, type: 'bar', stack: true, scale: { additions: '#42be65', deletions: '#fa4d56' }, showX: true }), [freqData, theme])

  const dash = (v: string | number | undefined | null) => (hasHistory && v != null ? String(v) : '—')

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
      {/* Controls + repo/sync status */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' }}>
        <ContentSwitcher selectedIndex={rangeIdx} onChange={({ index }) => setRangeIdx(index ?? 1)} size="sm" style={{ width: 220 }}>
          {RANGES.map((r) => (
            <Switch key={r.label} name={r.label} text={r.label} />
          ))}
        </ContentSwitcher>
        {repo && <Tag type="cool-gray" size="sm">{repo.provider}</Tag>}
        {repo?.path_prefix && <span className="scns-mono" style={{ fontSize: 12 }}>{repo.path_prefix}</span>}
        <div style={{ flex: 1 }} />
        <span className="scns-helper" style={{ fontSize: 12 }}>
          {repo?.last_synced_at
            ? `synced ${formatRelative(repo.last_synced_at, Date.now())}`
            : 'never synced'}
        </span>
        {repo?.last_synced_sha && <CopyableCode value={repo.last_synced_sha.slice(0, 10)} />}
      </div>

      {/* KPI strip */}
      <KpiCluster columns={6}>
        <KpiTile label="Commits" value={dash(summary && formatCompact(summary.total_commits))} valueSize={22} />
        <KpiTile label="Additions" value={dash(summary && `+${formatCompact(summary.total_additions)}`)} valueSize={22} />
        <KpiTile label="Deletions" value={dash(summary && `-${formatCompact(summary.total_deletions)}`)} valueSize={22} />
        <KpiTile label="Authors" value={dash(summary && formatInt(summary.total_authors))} valueSize={22} />
        <KpiTile label="Active days" value={dash(summary && formatInt(summary.active_days))} valueSize={22} />
        <KpiTile
          label="Last commit"
          value={summary?.last_commit_at ? formatRelative(summary.last_commit_at, Date.now()) : '—'}
          valueSize={22}
        />
      </KpiCluster>

      {state.loading ? (
        <div style={{ display: 'flex', justifyContent: 'center', padding: '64px 0' }}>
          <Loading withOverlay={false} description="Loading insights…" />
        </div>
      ) : state.error ? (
        <div className="scns-card">
          <EmptyState title="Insights unavailable" description={`Could not load insights: ${state.error}`} />
        </div>
      ) : !hasHistory ? (
        <div className="scns-card">
          <EmptyState
            title="No git history synced yet"
            description="Run commander:git:sync to pull this package's commit history into the insights tables."
          />
        </div>
      ) : (
        <>
          <ChartCard title="Contribution activity" subtitle={`commits per day · last ${RANGES[rangeIdx].label}`}>
            <EChart option={heatEOption} height={220} />
          </ChartCard>

          <ChartCard
            title="Code frequency"
            subtitle="lines changed per week"
            right={<ChartLegend items={[{ label: 'additions', color: '#42be65' }, { label: 'deletions', color: '#fa4d56' }]} />}
          >
            <EChart option={freqEOption} height={260} />
          </ChartCard>

          {/* Recent commits */}
          <div className="scns-card">
            <div style={{ padding: '16px 16px 8px', fontSize: 16, fontWeight: 600 }}>Recent commits</div>
            <TableContainer>
              <Table size="sm">
                <TableHead>
                  <TableRow>
                    <TableHeader>SHA</TableHeader>
                    <TableHeader>Message</TableHeader>
                    <TableHeader>Author</TableHeader>
                    <TableHeader>Date</TableHeader>
                    <TableHeader style={{ textAlign: 'right' }}>+/−</TableHeader>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {(insights?.recent_commits ?? []).map((c) => (
                    <TableRow key={c.sha}>
                      <TableCell className="scns-mono">{c.sha.slice(0, 8)}</TableCell>
                      <TableCell>{(c.message ?? '').split('\n')[0]}</TableCell>
                      <TableCell>{c.author_name ?? '—'}</TableCell>
                      <TableCell className="scns-mono">{c.committed_at ? formatDateUTC(c.committed_at) : '—'}</TableCell>
                      <TableCell style={{ textAlign: 'right' }} className="scns-num">
                        <span style={{ color: '#42be65' }}>+{formatInt(c.additions)}</span>{' '}
                        <span style={{ color: '#fa4d56' }}>−{formatInt(c.deletions)}</span>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </TableContainer>
          </div>
        </>
      )}
    </div>
  )
}
