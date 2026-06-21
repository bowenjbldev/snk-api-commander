import { type ReactNode } from 'react'
import { Button } from '@carbon/react'
import { Drawer } from './Drawer'

// Right-side slide-over for entity creation (Carbon "create" flow): keeps the list visible behind,
// gives the multi-field form room to breathe, less blocking than a centered modal. Built on the
// existing Drawer (no new dependency). Modals stay reserved for confirmations / state changes.
export function CreatePanel({
  open,
  title,
  label,
  submitLabel = 'Create',
  submitDisabled,
  onSubmit,
  onClose,
  children,
}: {
  open: boolean
  title: string
  /** Small overline label above the title (e.g. the entity collection). */
  label?: string
  submitLabel?: string
  submitDisabled?: boolean
  onSubmit: () => void
  onClose: () => void
  children: ReactNode
}) {
  return (
    <Drawer
      open={open}
      ariaLabel={title}
      onClose={onClose}
      header={
        <div>
          {label && <div className="scns-helper" style={{ fontSize: 12 }}>{label}</div>}
          <h2 style={{ fontSize: 24, fontWeight: 400, margin: label ? '2px 0 0' : 0 }}>{title}</h2>
        </div>
      }
      footer={
        <>
          <Button kind="secondary" style={{ flex: 1, maxWidth: 'none' }} onClick={onClose}>
            Cancel
          </Button>
          <Button kind="primary" style={{ flex: 1, maxWidth: 'none' }} onClick={onSubmit} disabled={submitDisabled}>
            {submitLabel}
          </Button>
        </>
      }
    >
      {children}
    </Drawer>
  )
}
