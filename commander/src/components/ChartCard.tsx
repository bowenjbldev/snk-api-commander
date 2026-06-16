import type { ReactNode } from 'react'

export function ChartLegend({ items }: { items: { label: string; color: string }[] }) {
  return (
    <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
      {items.map((it) => (
        <span key={it.label} style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
          <span style={{ width: 9, height: 9, background: it.color, flex: 'none' }} aria-hidden="true" />
          <span className="scns-mono" style={{ fontSize: 11, color: 'var(--cds-text-secondary)' }}>
            {it.label}
          </span>
        </span>
      ))}
    </div>
  )
}

export function ChartCard({
  title,
  subtitle,
  right,
  children,
}: {
  title: string
  subtitle?: string
  right?: ReactNode
  children: ReactNode
}) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: 12, gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, minWidth: 0 }}>
          <span style={{ fontSize: 16, fontWeight: 600 }}>{title}</span>
          {subtitle && (
            <span className="scns-helper" style={{ fontSize: 13 }}>
              {subtitle}
            </span>
          )}
        </div>
      </div>
      <div className="scns-card" style={{ padding: 16, flex: 1, display: 'flex', flexDirection: 'column' }}>
        {right && (
          <div style={{ alignSelf: 'flex-end', marginBottom: 16 }}>
            {right}
          </div>
        )}
        <div style={{ flex: 1 }}>
          {children}
        </div>
      </div>
    </div>
  )
}
