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
  /** Carbon icon (top-right). Inherits the tile's icon color unless a semantic class is set. */
  icon?: ReactNode
  /** Semantic icon tint, e.g. 'scns-val-amber' | 'scns-val-red' | 'scns-val-green'. */
  iconClass?: string
}

export function KpiTile({ label, value, helper, valueClass, valueSize, icon, iconClass }: KpiTileProps) {
  return (
    <div className="scns-kpi-tile">
      <div className="scns-kpi-top">
        <div className="scns-kpi-label">{label}</div>
        {icon != null && <span className={`scns-kpi-icon ${iconClass ?? ''}`} aria-hidden="true">{icon}</span>}
      </div>
      <div className={`scns-kpi-value ${valueClass ?? ''}`} style={valueSize ? { fontSize: valueSize } : undefined}>
        {value}
      </div>
      {helper != null && <div className="scns-kpi-helper">{helper}</div>}
    </div>
  )
}
