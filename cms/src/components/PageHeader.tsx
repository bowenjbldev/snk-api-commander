import type { ComponentType, ReactNode } from 'react'
import { Button } from '@carbon/react'

interface PrimaryAction {
  label: string
  icon?: ComponentType
  onClick?: () => void
}

interface Props {
  title: ReactNode
  subtitle?: ReactNode
  primaryAction?: PrimaryAction
  /** Extra controls rendered left of the primary action (e.g. Export). */
  actions?: ReactNode
  titleClassName?: string
}

export function PageHeader({ title, subtitle, primaryAction, actions, titleClassName }: Props) {
  return (
    <div
      style={{
        display: 'flex',
        justifyContent: 'space-between',
        alignItems: 'flex-start',
        gap: 16,
        marginBottom: 24,
      }}
    >
      <div style={{ minWidth: 0 }}>
        <h1 className={titleClassName} style={{ fontSize: 24, fontWeight: 400, margin: 0 }}>
          {title}
        </h1>
        {subtitle != null && (
          <div className="scns-helper" style={{ marginTop: 6, fontSize: 13 }}>
            {subtitle}
          </div>
        )}
      </div>
      {(actions || primaryAction) && (
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flex: 'none' }}>
          {actions}
          {primaryAction && (
            <Button renderIcon={primaryAction.icon} onClick={primaryAction.onClick}>
              {primaryAction.label}
            </Button>
          )}
        </div>
      )}
    </div>
  )
}
