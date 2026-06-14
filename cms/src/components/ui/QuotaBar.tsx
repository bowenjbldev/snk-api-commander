export type QuotaTone = 'normal' | 'warn' | 'over' | 'unlimited'

export function quotaTone(current: number, limit: number | null): QuotaTone {
  if (limit == null) return 'unlimited'
  const f = current / limit
  if (f > 1) return 'over'
  if (f >= 0.8) return 'warn'
  return 'normal'
}

/** 0..n fraction (1 === 100%); null limit returns 0. */
export function quotaFraction(current: number, limit: number | null): number {
  if (limit == null) return 0
  return current / limit
}

const TONE_COLOR: Record<QuotaTone, string> = {
  normal: 'var(--cds-text-helper)',
  warn: 'var(--sc-support-warning)',
  over: 'var(--sc-support-error)',
  unlimited: 'var(--cds-border-strong)',
}

/**
 * Thin quota utilisation bar. Fill is amber at ≥80%, red (full) past 100%,
 * neutral gray below; an unlimited quota renders an empty gray track.
 */
export function QuotaBar({
  current,
  limit,
  width = 120,
  height = 6,
}: {
  current: number
  limit: number | null
  width?: number | string
  height?: number
}) {
  const tone = quotaTone(current, limit)
  const f = quotaFraction(current, limit)
  const fillPct = tone === 'over' ? 100 : Math.min(100, Math.max(0, f * 100))
  return (
    <div
      style={{
        width,
        height,
        background: 'var(--cds-layer-accent)',
        position: 'relative',
        overflow: 'hidden',
      }}
      role="progressbar"
      aria-valuenow={Math.round(f * 100)}
      aria-valuemin={0}
      aria-valuemax={100}
    >
      {tone !== 'unlimited' && (
        <div
          style={{
            width: `${fillPct}%`,
            height: '100%',
            background: TONE_COLOR[tone],
          }}
        />
      )}
    </div>
  )
}

/** Red "OVER 124%" / amber pill helper for over-limit rows. */
export function OverBadge({ fraction }: { fraction: number }) {
  return (
    <span
      className="sc-mono"
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        height: 18,
        padding: '0 6px',
        borderRadius: 24,
        fontSize: 10,
        letterSpacing: 0.4,
        background: 'var(--cds-tag-background-red, #750e13)',
        color: 'var(--cds-tag-color-red, #ff8389)',
      }}
    >
      OVER {Math.round(fraction * 100)}%
    </span>
  )
}
