import { formatPercent } from '../lib/format'

interface Props {
  current: number
  limit: number | null // null == unlimited
  width?: number | string
  height?: number
  /** Right-side label: percent / OVER badge / "no limit". */
  showLabel?: boolean
}

export function fillColor(ratio: number): string {
  if (ratio > 1) return 'var(--scns-error)'
  if (ratio >= 0.8) return 'var(--scns-warning)'
  return 'var(--scns-success)'
}

// Thin usage bar: green < 80%, amber ≥ 80%, red + OVER badge > 100%, gray when
// unlimited. Used in the package mini-bar, Quotas table, and detail gauges.
export function QuotaBar({ current, limit, width = '100%', height = 6, showLabel }: Props) {
  const unlimited = limit == null
  const ratio = unlimited || limit === 0 ? 0 : current / limit
  const pct = Math.min(ratio, 1) * 100
  const over = ratio > 1

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8, minWidth: 0 }}>
      <div className="scns-qbar" style={{ height, width, flex: width === '100%' ? 1 : undefined }}>
        {!unlimited && (
          <div
            className="scns-qbar__fill"
            style={{ width: `${over ? 100 : pct}%`, background: fillColor(ratio) }}
          />
        )}
      </div>
      {showLabel && (
        <span
          className="scns-num"
          style={{
            fontSize: 11,
            flex: 'none',
            color: over
              ? 'var(--scns-error)'
              : ratio >= 0.8
                ? 'var(--scns-warn-text)'
                : 'var(--cds-text-secondary)',
          }}
        >
          {unlimited ? 'no limit' : over ? `OVER ${formatPercent(ratio)}` : formatPercent(ratio)}
        </span>
      )}
    </div>
  )
}
