import { useEffect, type ReactNode } from 'react'
import { IconButton } from '@carbon/react'
import { Close } from '@carbon/icons-react'

/**
 * Right-side drawer (424px). Carbon ships no drawer, so this keeps the mock's
 * structure: overlay + bordered panel below the 48px header, Esc to close.
 */
export function Drawer({
  open,
  onClose,
  title,
  headerExtra,
  children,
  footer,
}: {
  open: boolean
  onClose: () => void
  title: ReactNode
  headerExtra?: ReactNode
  children: ReactNode
  footer?: ReactNode
}) {
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null
  return (
    <>
      <div
        className="sc-drawer-overlay"
        onClick={onClose}
        aria-hidden="true"
      />
      <aside
        className="sc-drawer"
        role="dialog"
        aria-modal="true"
        aria-label={typeof title === 'string' ? title : 'Detail panel'}
      >
        <div
          style={{
            display: 'flex',
            alignItems: 'flex-start',
            justifyContent: 'space-between',
            gap: 8,
            padding: '16px',
            borderBottom: '1px solid var(--cds-border-subtle)',
          }}
        >
          <div style={{ minWidth: 0 }}>{title}</div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
            {headerExtra}
            <IconButton label="Close" kind="ghost" size="sm" onClick={onClose}>
              <Close />
            </IconButton>
          </div>
        </div>
        <div style={{ flex: 1, overflowY: 'auto' }}>{children}</div>
        {footer && (
          <div
            style={{
              borderTop: '1px solid var(--cds-border-subtle)',
              display: 'flex',
            }}
          >
            {footer}
          </div>
        )}
      </aside>
    </>
  )
}
