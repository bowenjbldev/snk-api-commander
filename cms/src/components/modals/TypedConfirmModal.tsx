import { useEffect, useState } from 'react'
import { Modal, TextInput } from '@carbon/react'

/**
 * Danger modal gated by typed confirmation: the operator must type
 * `confirmWord` exactly to enable the primary action. Used for irreversible
 * actions (delete deployment, rotate control secret).
 */
export function TypedConfirmModal({
  open,
  label,
  heading,
  body,
  confirmWord,
  confirmHelper,
  primaryText,
  onClose,
  onConfirm,
}: {
  open: boolean
  label?: string
  heading: string
  body: React.ReactNode
  confirmWord: string
  confirmHelper?: string
  primaryText: string
  onClose: () => void
  onConfirm: () => void
}) {
  const [typed, setTyped] = useState('')
  useEffect(() => {
    if (open) setTyped('')
  }, [open])

  const matches = typed.trim() === confirmWord
  return (
    <Modal
      danger
      open={open}
      size="sm"
      modalLabel={label}
      modalHeading={heading}
      primaryButtonText={primaryText}
      secondaryButtonText="Cancel"
      primaryButtonDisabled={!matches}
      onRequestClose={onClose}
      onRequestSubmit={() => {
        if (matches) onConfirm()
      }}
    >
      <div style={{ marginBottom: 16 }}>{body}</div>
      <TextInput
        id="typed-confirm"
        labelText={
          <>
            Type <span className="sc-mono">{confirmWord}</span> to confirm
          </>
        }
        helperText={confirmHelper}
        value={typed}
        onChange={(e) => setTyped(e.target.value)}
        autoComplete="off"
      />
    </Modal>
  )
}
