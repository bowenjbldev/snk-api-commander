import { Tag } from '@carbon/react'
import type { RecentCommit } from '../store/types'
import type { PackageTagType } from '../lib/chartData'
import { RelativeTime } from './RelativeTime'
import { formatInt } from '../lib/format'

// GitHub-network-style commit graph laid out as a table: a graph gutter (coloured lane per package +
// a node per commit) as the first column, then Description / Date / Author / SHA / +−. We have no
// parent SHAs, so topology is decorative — lanes run parallel and each non-trunk lane sprouts from the
// first lane ("trunk") with a branch-off curve at its newest commit.
const LANE_GAP = 16 // px between lanes
const ROW_H = 40 // px per commit row (must match .scns-cardtable__row height via the svg)
const NODE_R = 4.5
const PAD_X = 10 // left inset of the first lane (the trunk) inside the gutter
const META_COLS = '96px 132px 80px 104px' // Date · Author · SHA · +/−

interface Props {
  commits: RecentCommit[]
  /** Lane/rail colour for a package name (null → fallback grey). Kept in sync with the chart palette. */
  colorOf: (pkg: string | null | undefined) => string
  /** Carbon Tag type for a package name (matches the lane hue). */
  tagTypeOf: (pkg: string | null | undefined) => PackageTagType
  onOpenPackage: (c: RecentCommit) => void
}

export function CommitGraph({ commits, colorOf, tagTypeOf, onOpenPackage }: Props) {
  const keyOf = (c: RecentCommit) => c.package ?? `pkg-${c.package_id ?? '?'}`
  const laneColor = (k: string) => colorOf(k.startsWith('pkg-') ? null : k)

  // Lanes in order of first (newest) appearance; track each lane's first/last row so its rail spans
  // only where the package actually has commits.
  const lanes: string[] = []
  const laneOf = new Map<string, number>()
  const firstRow = new Map<string, number>()
  const lastRow = new Map<string, number>()
  commits.forEach((c, i) => {
    const k = keyOf(c)
    if (!laneOf.has(k)) {
      laneOf.set(k, lanes.length)
      lanes.push(k)
    }
    if (!firstRow.has(k)) firstRow.set(k, i)
    lastRow.set(k, i)
  })
  const gutterW = PAD_X + Math.max(1, lanes.length) * LANE_GAP
  const trunkLastRow = lanes.length ? lastRow.get(lanes[0])! : -1 // lane 0 = trunk, starts at row 0
  const X0 = PAD_X

  // Grid template shared by the header + every row, so columns line up. The graph column is at least
  // wide enough for the "Graph" header.
  const cols = `${Math.max(gutterW, 56)}px minmax(0, 1fr) ${META_COLS}`

  return (
    <div className="scns-commitgraph">
      <div className="scns-cardtable__head" style={{ gridTemplateColumns: cols }}>
        <span>Graph</span>
        <span>Description</span>
        <span>Date</span>
        <span>Author</span>
        <span>SHA</span>
        <span className="scns-cardtable__num">+/−</span>
      </div>
      {commits.map((c, i) => {
        const k = keyOf(c)
        const mine = laneOf.get(k)!
        const xMine = PAD_X + mine * LANE_GAP
        const isLaneStart = mine > 0 && i === firstRow.get(k)! // newest commit of a non-trunk branch
        const branchesOffTrunk = isLaneStart && i <= trunkLastRow
        return (
          <div className="scns-cardtable__row scns-cardtable__row--link" key={`${c.package_id}-${c.sha}`} style={{ gridTemplateColumns: cols }} onClick={() => onOpenPackage(c)}>
            <svg className="scns-commitgraph__svg" width={gutterW} height={ROW_H} aria-hidden="true">
              {lanes.map((lk, l) => {
                if (i < firstRow.get(lk)! || i > lastRow.get(lk)!) return null // rail not alive on this row
                const x = PAD_X + l * LANE_GAP
                const color = laneColor(lk)
                return (
                  <g key={lk}>
                    {firstRow.get(lk)! < i && <line x1={x} y1={0} x2={x} y2={ROW_H / 2} stroke={color} strokeWidth={2} opacity={0.5} />}
                    {lastRow.get(lk)! > i && <line x1={x} y1={ROW_H / 2} x2={x} y2={ROW_H} stroke={color} strokeWidth={2} opacity={0.5} />}
                  </g>
                )
              })}
              {/* Branch-off curve: this lane's newest commit sprouts from the trunk (lane 0) below it. */}
              {branchesOffTrunk && (
                <path
                  d={`M ${xMine},${ROW_H / 2} C ${xMine},${ROW_H * 0.82} ${X0},${ROW_H * 0.62} ${X0},${ROW_H}`}
                  fill="none"
                  stroke={laneColor(k)}
                  strokeWidth={2}
                  opacity={0.5}
                />
              )}
              <circle cx={xMine} cy={ROW_H / 2} r={NODE_R} fill={laneColor(k)} stroke="var(--cds-layer)" strokeWidth={2} />
            </svg>

            <div className="scns-commitgraph__desc">
              <span className="scns-commitgraph__pkgbtn" title={`Open ${c.package ?? 'package'}`}>
                <Tag type={tagTypeOf(c.package)} size="sm">{c.package ?? '—'}</Tag>
              </span>
              <span className="scns-commitgraph__msg" title={c.message ?? ''}>
                {(c.message ?? '').split('\n')[0] || '—'}
              </span>
            </div>

            <span className="scns-cardtable__cell">{c.committed_at ? <RelativeTime iso={c.committed_at} /> : '—'}</span>
            <span className="scns-cardtable__cell">{c.author_name ?? '—'}</span>
            <span className="scns-cardtable__cell scns-mono">{c.sha.slice(0, 8)}</span>
            <span className="scns-cardtable__cell scns-cardtable__num scns-num">
              <span style={{ color: '#42be65' }}>+{formatInt(c.additions)}</span>{' '}
              <span style={{ color: '#fa4d56' }}>−{formatInt(c.deletions)}</span>
            </span>
          </div>
        )
      })}
    </div>
  )
}
