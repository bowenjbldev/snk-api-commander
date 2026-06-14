import {
  Button,
  ComposedModal,
  ModalBody,
  ModalFooter,
  ModalHeader,
} from '@carbon/react'
import type { Invoice } from '../../types'
import { simNowMs } from '../../lib/time'
import { dateTimeUTC, fmtUSD } from '../../lib/format'

export type OverdueChoice = 'keep' | 'grace' | 'suspend'

/**
 * Enforcement decision after marking an invoice overdue. Three equal actions:
 * Keep running / Grace 72h / Suspend now.
 */
export function OverdueModal({
  open,
  invoice,
  deploymentName,
  deploymentOperational,
  onClose,
  onChoice,
}: {
  open: boolean
  invoice: Invoice | null
  deploymentName: string
  deploymentOperational: boolean
  onClose: () => void
  onChoice: (choice: OverdueChoice, graceUntilIso: string) => void
}) {
  if (!invoice) return null
  const deadlineIso = new Date(simNowMs() + 72 * 3600 * 1000).toISOString()
  return (
    <ComposedModal open={open} onClose={onClose} size="md">
      <ModalHeader
        label={`${invoice.number} · ${invoice.clientName} · ${fmtUSD(
          invoice.amount,
          { cents: true },
        )}`}
        title="Invoice marked as overdue"
      />
      <ModalBody>
        <p style={{ marginBottom: 16 }}>
          The linked deployment{' '}
          <span className="sc-mono">{deploymentName}</span> is{' '}
          {deploymentOperational ? 'still operational' : 'already affected'}.
          Auto-suspend is off for this subscription — choose what happens to the
          deployment now.
        </p>
        <div
          style={{
            borderLeft: '3px solid var(--sc-support-info, #4589ff)',
            background: 'var(--cds-layer-accent)',
            padding: '10px 12px',
            fontSize: 13,
          }}
        >
          Grace applies the default +72h deadline →{' '}
          <span className="sc-mono">{dateTimeUTC(deadlineIso)}</span>.
          Configurable in Settings.
        </div>
      </ModalBody>
      <ModalFooter>
        <Button kind="secondary" onClick={() => onChoice('keep', deadlineIso)}>
          Keep running
        </Button>
        <Button kind="primary" onClick={() => onChoice('grace', deadlineIso)}>
          Grace 72h
        </Button>
        <Button kind="danger" onClick={() => onChoice('suspend', deadlineIso)}>
          Suspend now
        </Button>
      </ModalFooter>
    </ComposedModal>
  )
}
