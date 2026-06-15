import type { ReactNode } from 'react'

export function KpiCluster({ columns = 6, children }: { columns?: number; children: ReactNode }) {
  return (
    <div className="scns-kpi-cluster" style={{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }}>
      {children}
    </div>
  )
}

interface KpiTileProps {
  label: string
  value: ReactNode
  helper?: ReactNode
  /** Semantic value color, e.g. 'scns-val-amber' | 'scns-val-red' | 'scns-val-green'. */
  valueClass?: string
  valueSize?: number
}

export function KpiTile({ label, value, helper, valueClass, valueSize }: KpiTileProps) {
  return (
    <div className="scns-kpi-tile">
      <div className="scns-kpi-label">{label}</div>
      <div className={`scns-kpi-value ${valueClass ?? ''}`} style={valueSize ? { fontSize: valueSize } : undefined}>
        {value}
      </div>
      {helper != null && <div className="scns-kpi-helper">{helper}</div>}
    </div>
  )
}
