import type { ReactNode } from 'react'
import { Card } from './Card'

export function ChartLegend({ items }: { items: { label: string; color: string }[] }) {
  return (
    <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', justifyContent: 'flex-start' }}>
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
    <Card title={title} subtitle={subtitle} className="scns-chartcard" bodyPadding={16}>
      <div style={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
        {right && <div style={{ alignSelf: 'flex-start', marginBottom: 16 }}>{right}</div>}
        <div style={{ flex: 1 }}>{children}</div>
      </div>
    </Card>
  )
}
