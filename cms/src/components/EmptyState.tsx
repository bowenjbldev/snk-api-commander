import type { ReactNode } from 'react'
import { Button } from '@carbon/react'

interface Props {
  title: string
  description?: string
  action?: { label: string; onClick: () => void }
  children?: ReactNode
}

// Geometric placeholder mark + copy + optional primary action.
export function EmptyState({ title, description, action, children }: Props) {
  return (
    <div
      style={{
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        textAlign: 'center',
        padding: '64px 24px',
        gap: 12,
      }}
    >
      <svg width="56" height="56" viewBox="0 0 56 56" fill="none" aria-hidden="true">
        <rect x="4" y="4" width="48" height="48" stroke="var(--cds-border-strong)" />
        <path d="M4 20h48M20 20v32" stroke="var(--cds-border-subtle)" />
        <rect x="28" y="28" width="16" height="16" stroke="var(--cds-border-strong)" />
      </svg>
      <div style={{ fontSize: 16, fontWeight: 600 }}>{title}</div>
      {description && (
        <div className="scns-helper" style={{ maxWidth: 360 }}>
          {description}
        </div>
      )}
      {children}
      {action && (
        <Button style={{ marginTop: 8 }} onClick={action.onClick}>
          {action.label}
        </Button>
      )}
    </div>
  )
}
