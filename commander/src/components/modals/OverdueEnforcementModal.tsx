import { Button, ComposedModal, ModalBody, ModalFooter, ModalHeader, InlineNotification } from '@carbon/react'
import type { Deployment, Invoice } from '../../store/types'
import { formatCurrency } from '../../lib/format'
import { formatDateUTC as fmtDeadline } from '../../lib/time'

interface Props {
  open: boolean
  invoice: Invoice
  deployment: Deployment | undefined
  clientName: string
  defaultGraceHours: number
  onClose: () => void
  onKeep: () => void
  onGrace: () => void
  onSuspend: () => void
}

// Follow-up after marking an invoice overdue: decide the linked deployment's fate.
// Three equal actions: Keep running / Grace Nh / Suspend now.
export function OverdueEnforcementModal({
  open,
  invoice,
  deployment,
  clientName,
  defaultGraceHours,
  onClose,
  onKeep,
  onGrace,
  onSuspend,
}: Props) {
  if (!open) return null
  const deadline = fmtDeadline(new Date(Date.now() + defaultGraceHours * 3_600_000).toISOString())

  return (
    <ComposedModal open onClose={onClose} size="md">
      <ModalHeader
        label={`${invoice.id} · ${clientName} · ${formatCurrency(invoice.amount)}`}
        title="Invoice marked as overdue"
      />
      <ModalBody>
        <p style={{ marginBottom: 16 }}>
          The linked deployment{' '}
          <span className="scns-mono">{deployment?.package.name ?? invoice.deployment_id}</span> is still
          operational. Auto-suspend is off for this subscription — choose what happens to the deployment
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
