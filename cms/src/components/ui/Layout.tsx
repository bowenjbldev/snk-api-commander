import type { CSSProperties, ReactNode } from 'react'

/** Standard page header: title (24/400), optional count subline + right slot. */
export function PageHeader({
  title,
  titleMono,
  subtitle,
  right,
}: {
  title: ReactNode
  titleMono?: boolean
  subtitle?: ReactNode
  right?: ReactNode
}) {
  return (
    <div
      style={{
        display: 'flex',
        alignItems: 'flex-start',
        justifyContent: 'space-between',
        gap: 16,
        flexWrap: 'wrap',
        marginBottom: 24,
      }}
    >
      <div style={{ minWidth: 0 }}>
        <h1
          className={titleMono ? 'sc-mono' : undefined}
          style={{ margin: 0, fontSize: 24, fontWeight: 400, lineHeight: 1.2 }}
        >
          {title}
        </h1>
        {subtitle && (
          <div
            style={{
              marginTop: 6,
              fontSize: 13,
              color: 'var(--cds-text-helper)',
            }}
          >
            {subtitle}
          </div>
        )}
      </div>
      {right && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          {right}
        </div>
      )}
    </div>
  )
}

/** KPI tile, designed to sit inside a `.sc-tile-cluster` grid. */
export function KpiTile({
  label,
  value,
  valueColor,
  helper,
  helperColor,
}: {
  label: ReactNode
  value: ReactNode
  valueColor?: string
  helper?: ReactNode
  helperColor?: string
  valueSize?: number
}) {
  return (
    <div
      style={{
        background: 'var(--cds-layer)',
        padding: '14px 16px 16px',
        minWidth: 0,
      }}
    >
      <div style={{ fontSize: 12, color: 'var(--cds-text-secondary)' }}>
        {label}
      </div>
      <div
        style={{
          fontSize: 28,
          fontWeight: 400,
          lineHeight: 1.2,
          marginTop: 4,
          color: valueColor,
        }}
      >
        {value}
      </div>
      {helper != null && (
        <div
          style={{
            fontSize: 12,
            marginTop: 4,
            color: helperColor ?? 'var(--cds-text-helper)',
          }}
        >
          {helper}
        </div>
      )}
    </div>
  )
}

/** Bordered layer card with optional title row and left accent border. */
export function Card({
  title,
  right,
  accent,
  bodyStyle,
  children,
}: {
  title?: ReactNode
  right?: ReactNode
  /** Left accent color (e.g. amber warning state card). */
  accent?: string
  bodyStyle?: CSSProperties
  children: ReactNode
}) {
  return (
    <section
      className="sc-card"
      style={
        accent
          ? { borderLeft: `3px solid ${accent}` }
          : undefined
      }
    >
      {(title || right) && (
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: 8,
            padding: '14px 16px',
            borderBottom: '1px solid var(--cds-border-subtle)',
          }}
        >
          <div style={{ fontSize: 16, fontWeight: 600 }}>{title}</div>
          {right}
        </div>
      )}
      <div style={{ padding: 16, ...bodyStyle }}>{children}</div>
    </section>
  )
}

/** Key/value row used in drawers and detail blocks. */
export function KV({
  label,
  children,
  align = 'right',
}: {
  label: ReactNode
  children: ReactNode
  align?: 'left' | 'right'
}) {
  return (
    <div
      style={{
        display: 'flex',
        justifyContent: 'space-between',
        gap: 12,
        alignItems: 'baseline',
        padding: '10px 0',
        borderBottom: '1px solid var(--cds-border-subtle)',
        fontSize: 13,
      }}
    >
      <span style={{ color: 'var(--cds-text-helper)', flex: 'none' }}>
        {label}
      </span>
      <span
        style={{
          textAlign: align,
          color: 'var(--cds-text-primary)',
          minWidth: 0,
        }}
      >
        {children}
      </span>
    </div>
  )
}

/** Empty state: geometric glyph + copy + optional primary action. */
export function EmptyState({
  title,
  description,
  action,
}: {
  title: string
  description?: string
  action?: ReactNode
}) {
  return (
    <div
      style={{
        textAlign: 'center',
        color: 'var(--cds-text-helper)',
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        gap: 12,
      }}
    >
      <svg width="48" height="48" viewBox="0 0 48 48" fill="none" aria-hidden>
        <rect
          x="6"
          y="6"
          width="36"
          height="36"
          stroke="var(--cds-border-strong)"
        />
        <path d="M6 18h36M18 6v36" stroke="var(--cds-border-strong)" />
      </svg>
      <div style={{ fontSize: 16, color: 'var(--cds-text-primary)' }}>
        {title}
      </div>
      {description && <div style={{ fontSize: 13 }}>{description}</div>}
      {action}
    </div>
  )
}
