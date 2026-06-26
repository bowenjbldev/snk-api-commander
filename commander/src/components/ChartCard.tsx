import type { ReactNode } from 'react'
import { Card } from './Card'

export function ChartLegend({ items }: { items: { label: string; color: string }[] }) {
  return (
    <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', justifyContent: 'center' }}>
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
  action,
  fill,
  footer,
  children,
}: {
  title: string
  subtitle?: string
  right?: ReactNode
  action?: ReactNode
  /** When true, the chart GROWS to fill the card height (pass the chart a height of '100%') so the
      legend hugs the bottom with no empty gap — use on a card that the grid stretches to match a
      taller sibling column. Otherwise the chart keeps its fixed pixel height and hugs its content. */
  fill?: boolean
  /** Optional compact summary band under the chart (a stat the chart's shape doesn't quantify). */
  footer?: ReactNode
  children: ReactNode
}) {
  return (
    <Card title={title} subtitle={subtitle} action={action} footer={footer} className="scns-chartcard" bodyPadding={16}>
      <div style={{ display: 'flex', flexDirection: 'column', height: fill ? '100%' : undefined }}>
        <div style={fill ? { flex: 1, minHeight: 0 } : undefined}>{children}</div>
        {right && <div style={{ alignSelf: 'center', marginTop: 12 }}>{right}</div>}
      </div>
    </Card>
  )
}
