import type { ReactNode } from 'react'
import { IconButton } from '@carbon/react'
import { Close } from '@carbon/icons-react'

interface Props {
  title: string
  onClose: () => void
  headerExtra?: ReactNode
  children: ReactNode
}

// 340px in-layout (non-modal) detail panel beside the request-logs table.
export function DetailPanel({ title, onClose, headerExtra, children }: Props) {
  return (
    <aside className="scns-panel" aria-label={title}>
      <div className="scns-drawer__header">
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span style={{ fontSize: 14, fontWeight: 600 }}>{title}</span>
          {headerExtra}
        </div>
        <IconButton kind="ghost" size="sm" label="Close" onClick={onClose}>
          <Close />
        </IconButton>
      </div>
      <div style={{ padding: 16 }}>{children}</div>
    </aside>
  )
}
