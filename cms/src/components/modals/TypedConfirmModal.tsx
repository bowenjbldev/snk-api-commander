import { useEffect, useState, type ReactNode } from 'react'
import { Modal, TextInput } from '@carbon/react'

interface Props {
  open: boolean
  title: string
  label?: string
  body: ReactNode
  /** The exact text the operator must type to enable the danger action. */
  confirmWord: string
  confirmLabel: string
  onClose: () => void
  onConfirm: () => void
}

// Destructive / irreversible actions (delete, secret rotation): the danger button
// stays disabled until the operator types the entity name exactly.
export function TypedConfirmModal({
  open,
  title,
  label,
  body,
  confirmWord,
  confirmLabel,
  onClose,
  onConfirm,
}: Props) {
  const [val, setVal] = useState('')
  useEffect(() => {
    if (open) setVal('')
  }, [open])

  if (!open) return null

  return (
    <Modal
      open
      danger
      modalLabel={label}
      modalHeading={title}
      primaryButtonText={confirmLabel}
      secondaryButtonText="Cancel"
      primaryButtonDisabled={val !== confirmWord}
      onRequestClose={onClose}
      onRequestSubmit={() => {
        if (val === confirmWord) onConfirm()
      }}
    >
      <div style={{ marginBottom: 16 }}>{body}</div>
      <TextInput
        id="typed-confirm"
        labelText={
          <>
            Type <code className="scns-mono">{confirmWord}</code> to confirm
          </>
        }
        value={val}
        onChange={(e) => setVal(e.target.value)}
        autoComplete="off"
      />
    </Modal>
  )
}
