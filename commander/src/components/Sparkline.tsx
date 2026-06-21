// Tiny inline SVG sparkline — a lightweight trend line for table cells where a real
// Carbon chart would be far too heavy (one chart per row re-lays-out on every render).
// Pure presentational: no state, no tick subscription, no library. Feed it a numeric
// series (e.g. hourly request counts) and it draws a normalized polyline + soft area.

interface Props {
  data: number[]
  width?: number
  height?: number
  /** Line color; the area uses the same color at low opacity. */
  color?: string
  className?: string
}

export function Sparkline({ data, width = 72, height = 22, color = 'var(--cds-link-primary)', className }: Props) {
  const pad = 1.5
  const n = data.length

  // Nothing to plot (still loading or no traffic): a faint baseline keeps the row height stable.
  if (n < 2 || data.every((v) => v === 0)) {
    return (
      <svg width={width} height={height} className={className} aria-hidden="true" style={{ display: 'block' }}>
        <line
          x1={pad}
          y1={height - pad}
          x2={width - pad}
          y2={height - pad}
          stroke="var(--cds-border-subtle)"
          strokeWidth={1}
        />
      </svg>
    )
  }

  const max = Math.max(...data)
  const min = Math.min(...data)
  const span = max - min || 1
  const innerW = width - pad * 2
  const innerH = height - pad * 2

  const points = data.map((v, i) => {
    const x = pad + (i / (n - 1)) * innerW
    const y = pad + (1 - (v - min) / span) * innerH
    return [x, y] as const
  })

  const line = points.map(([x, y]) => `${x.toFixed(1)},${y.toFixed(1)}`).join(' ')
  const area = `${pad},${height - pad} ${line} ${(width - pad).toFixed(1)},${height - pad}`

  return (
    <svg width={width} height={height} className={className} aria-hidden="true" style={{ display: 'block' }}>
      <polygon points={area} fill={color} opacity={0.12} />
      <polyline points={line} fill="none" stroke={color} strokeWidth={1.25} strokeLinejoin="round" strokeLinecap="round" />
    </svg>
  )
}
