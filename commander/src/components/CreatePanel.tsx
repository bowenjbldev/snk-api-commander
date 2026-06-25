import { type ReactNode } from 'react'
import { CreateSidePanel } from '@carbon/ibm-products'

// Right-side create flow — the canonical Carbon "Side panel" pattern (CreateSidePanel from
// @carbon/ibm-products). It uses the `slideIn` variant: NO overlay, it pushes the page content
// (`selectorPageContent`) aside so the list stays visible and operable behind it — exactly the
// create-flows rule for medium-complexity forms where the user needs page context. The panel docks
// below the 48px header by default (inset-block-start: 3rem) and is a content-area surface, so it
// follows the active theme. Modals stay reserved for confirmations / state changes.
export function CreatePanel({
  open,
  title,
  subtitle,
  formTitle = 'Details',
  submitLabel = 'Create',
  submitDisabled,
  onSubmit,
  onClose,
  children,
}: {
  open: boolean
  title: string
  /** Optional descriptive line under the title. */
  subtitle?: ReactNode
  /** Heading above the form body (Carbon requires one for create flows). */
  formTitle?: ReactNode
  submitLabel?: string
  submitDisabled?: boolean
  onSubmit: () => void
  onClose: () => void
  children: ReactNode
}) {
  return (
    <CreateSidePanel
      className="scns-create-flow"
      open={open}
      title={title}
      subtitle={subtitle}
      formTitle={formTitle}
      primaryButtonText={submitLabel}
      secondaryButtonText="Cancel"
      disableSubmit={submitDisabled}
      onRequestSubmit={onSubmit}
      onRequestClose={onClose}
      selectorPageContent="#main-content"
      selectorPrimaryFocus=".scns-create-flow input:not([disabled]), .scns-create-flow textarea:not([disabled])"
    >
      {children}
    </CreateSidePanel>
  )
}
