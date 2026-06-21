import { useEffect, useState } from 'react'
import { Modal, Select, SelectItem, TextArea, NumberInput, InlineNotification } from '@carbon/react'
import type { Package } from '../../store/types'
import { formatDateUTC } from '../../lib/time'
import { packageFqdn } from '../../lib/package'

export interface StateChangeResult {
  reason: string
  message: string
  graceHours?: number
}

interface Props {
  mode: 'suspend' | 'grace'
  targets: Package[]
  defaultGraceHours: number
  open: boolean
  onClose: () => void
  onConfirm: (result: StateChangeResult) => void
}

const REASONS = [
  { value: 'payment_overdue', label: 'payment_overdue' },
  { value: 'abuse', label: 'abuse' },
  { value: 'manual', label: 'manual' },
]

// Suspend (danger) / Grace (primary) modal. Grace adds a deadline control. Suspend
// is reversible so it needs no typed confirmation. Handles one or many targets.
export function StateChangeModal({ mode, targets, defaultGraceHours, open, onClose, onConfirm }: Props) {
  const single = targets.length === 1 ? targets[0] : null
  const [reason, setReason] = useState('payment_overdue')
  const [message, setMessage] = useState('')
  const [hours, setHours] = useState(defaultGraceHours)

  useEffect(() => {
    if (open) {
      setReason(mode === 'grace' ? 'payment_overdue' : 'payment_overdue')
      setMessage(single?.message ?? '')
      setHours(defaultGraceHours)
    }
  }, [open, mode, single, defaultGraceHours])

  if (!open || targets.length === 0) return null

  const isSuspend = mode === 'suspend'
  const heading = single
    ? isSuspend
      ? `Suspend package ${single.name}?`
      : `Move ${single.name} to grace`
    : isSuspend
      ? `Suspend ${targets.length} packages?`
      : `Move ${targets.length} packages to grace`
  const label = single ? packageFqdn(single) : `${targets.length} packages selected`
  const graceUntilPreview = formatDateUTC(new Date(Date.now() + hours * 3_600_000).toISOString())
  const suspendingGrace = isSuspend && single?.status === 'grace'

  return (
    <Modal
      open
      danger={isSuspend}
      modalLabel={label}
      modalHeading={heading}
      primaryButtonText={isSuspend ? 'Suspend package' : 'Move to grace'}
      secondaryButtonText="Cancel"
      onRequestClose={onClose}
      onRequestSubmit={() => onConfirm({ reason, message, graceHours: isSuspend ? undefined : hours })}
    >
      <p style={{ marginBottom: 16 }}>
        {isSuspend ? (
          <>
            The API stops serving immediately. All users of {single ? 'this package' : 'these packages'}{' '}
            will receive <strong>HTTP 402 Payment Required</strong> until resumed.
          </>
        ) : (
          <>
            The API keeps serving during the grace period and returns warning headers. At the deadline it is
            suspended automatically (HTTP 402).
          </>
        )}
      </p>

      {suspendingGrace && single?.grace_until && (
        <InlineNotification
          kind="warning"
          lowContrast
          hideCloseButton
          title="Skips the grace deadline"
          subtitle="This package is in grace. Suspending now ends service before the deadline."
          style={{ marginBottom: 16, maxWidth: '100%' }}
        />
      )}

      <Select
        id="state-reason"
        labelText="Reason *"
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        helperText="Stored on the state record and shown in the activity feed."
      >
        {REASONS.map((r) => (
          <SelectItem key={r.value} value={r.value} text={r.label} />
        ))}
      </Select>

      <div style={{ marginTop: 16 }}>
        <TextArea
          id="state-message"
          labelText="Client-facing message"
          helperText="Returned in the 402 response body."
          value={message}
          onChange={(e) => setMessage(e.target.value.slice(0, 280))}
          enableCounter
          maxCount={280}
          rows={3}
        />
      </div>

      {!isSuspend && (
        <div style={{ marginTop: 16 }}>
          <NumberInput
            id="grace-hours"
            label="Grace period (hours)"
            min={1}
            max={720}
            value={hours}
            onChange={(_e, { value }) => setHours(Number(value) || defaultGraceHours)}
          />
          <div className="scns-helper scns-mono" style={{ marginTop: 8, fontSize: 11 }}>
            Suspends at {graceUntilPreview}
          </div>
        </div>
      )}
    </Modal>
  )
}
