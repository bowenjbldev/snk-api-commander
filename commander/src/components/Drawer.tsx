import { useEffect, useRef, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { IconButton } from '@carbon/react'
import { Close } from '@carbon/icons-react'
import { useFocusTrap } from '../hooks/useFocusTrap'

interface Props {
  open: boolean
  onClose: () => void
  ariaLabel: string
  header: ReactNode
  footer?: ReactNode
  children: ReactNode
}

// 424px right-side modal drawer (Carbon has no drawer). Portaled to <body>, which
// carries the theme zone class, so tokens resolve. Esc + overlay click close it.
export function Drawer({ open, onClose, ariaLabel, header, footer, children }: Props) {
  const panelRef = useRef<HTMLElement>(null)
  useFocusTrap(panelRef, open)
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null

  return createPortal(
    <>
      <div className="scns-overlay" onClick={onClose} />
      <aside ref={panelRef} className="scns-drawer" role="dialog" aria-modal="true" aria-label={ariaLabel}>
        <div className="scns-drawer__header">
          <div style={{ minWidth: 0 }}>{header}</div>
          <IconButton kind="ghost" size="sm" label="Close" align="left" onClick={onClose}>
            <Close />
          </IconButton>
        </div>
        <div style={{ flex: 1, padding: 16, overflow: 'hidden auto' }}>{children}</div>
        {footer && (
          <div style={{ borderTop: '1px solid var(--cds-border-subtle)', display: 'flex' }}>{footer}</div>
        )}
      </aside>
    </>,
    document.body,
  )
}
