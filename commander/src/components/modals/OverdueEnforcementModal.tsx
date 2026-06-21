import { Button, ComposedModal, ModalBody, ModalFooter, ModalHeader, InlineNotification } from '@carbon/react'
import type { Invoice, Package } from '../../store/types'
import { formatCurrency } from '../../lib/format'
import { formatDateUTC as fmtDeadline } from '../../lib/time'

interface Props {
  open: boolean
  invoice: Invoice
  pkg: Package | undefined
  clientName: string
  defaultGraceHours: number
  onClose: () => void
  onKeep: () => void
  onGrace: () => void
  onSuspend: () => void
}

// Follow-up after marking an invoice overdue: decide the linked package's fate.
// Three equal actions: Keep running / Grace Nh / Suspend now.
export function OverdueEnforcementModal({
  open,
  invoice,
  pkg,
  clientName,
  defaultGraceHours,
  onClose,
  onKeep,
  onGrace,
  onSuspend,
}: Props) {
  if (!open) return null
  const deadline = fmtDeadline(new Date(Date.now() + defaultGraceHours * 3_600_000).toISOString())
  const packageLabel = pkg?.name ?? 'Unknown package'

  return (
    <ComposedModal open onClose={onClose} size="md">
      <ModalHeader
        label={`${invoice.number} · ${clientName} · ${formatCurrency(invoice.amount)}`}
        title="Invoice marked as overdue"
      />
      <ModalBody>
        <p style={{ marginBottom: 16 }}>
          The linked package{' '}
          <span className="scns-mono">{packageLabel}</span> is still
          operational. Auto-suspend is off for this subscription — choose what happens to the package
          now.
        </p>
        <InlineNotification
          kind="info"
          lowContrast
          hideCloseButton
          title="Grace deadline"
          subtitle={`Grace applies the default +${defaultGraceHours}h deadline → ${deadline}. Configurable in Settings.`}
          style={{ maxWidth: '100%' }}
        />
      </ModalBody>
      <ModalFooter>
        <Button kind="secondary" style={{ flex: 1, maxWidth: 'none' }} onClick={onKeep}>
          Keep running
        </Button>
        <Button kind="primary" style={{ flex: 1, maxWidth: 'none' }} onClick={onGrace}>
          Grace {defaultGraceHours}h
        </Button>
        <Button kind="danger" style={{ flex: 1, maxWidth: 'none' }} onClick={onSuspend}>
          Suspend now
        </Button>
      </ModalFooter>
    </ComposedModal>
  )
}
