// Segmented mini-bar showing a client's package status mix (active / grace / suspended)
// in one glance — CSS-only, the same lightweight family as QuotaBar (.scns-qbar). The
// count label sits beside it. Empty (no packages) renders a faint placeholder bar.

interface Props {
  active: number
  grace: number
  suspended: number
}

export function StatusMixBar({ active, grace, suspended }: Props) {
  const total = active + grace + suspended
  const parts: Array<[number, string]> = [
    [active, 'var(--scns-success)'],
    [grace, 'var(--scns-warning)'],
    [suspended, 'var(--scns-error)'],
  ]
  const title = total === 0
    ? 'No packages'
    : [active && `${active} active`, grace && `${grace} grace`, suspended && `${suspended} suspended`]
        .filter(Boolean)
        .join(' · ')

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8, minWidth: 0 }} title={title}>
      <div className="scns-statusmix" style={{ flex: 1 }}>
        {total === 0
          ? null
          : parts.map(([n, color], i) =>
              n > 0 ? (
                <span
                  key={i}
                  className="scns-statusmix__seg"
                  style={{ width: `${(n / total) * 100}%`, background: color }}
                />
              ) : null,
            )}
      </div>
      <span className="scns-num" style={{ fontSize: 12, flex: 'none', color: 'var(--cds-text-secondary)' }}>
        {total}
      </span>
    </div>
  )
}
