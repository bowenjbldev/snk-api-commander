import { useEffect, useState } from 'react'
import {
  DatePicker,
  DatePickerInput,
  Modal,
  Select,
  SelectItem,
  TextArea,
  TimePicker,
} from '@carbon/react'
import type { Deployment, StateReason } from '../../types'
import { useNowMs, simNowMs } from '../../lib/time'
import { countdownShort, dateTimeUTC } from '../../lib/format'
import { Mono } from '../ui/Bits'

const REASONS: StateReason[] = ['payment_overdue', 'abuse', 'manual']

function targetLabel(targets: Deployment[]): string {
  return targets.length === 1 ? targets[0].subdomain : `${targets.length} selected`
}

function GraceContextBlock({ targets }: { targets: Deployment[] }) {
  const nowMs = useNowMs()
  const single = targets.length === 1 ? targets[0] : null
  if (!single || single.state.status !== 'grace' || !single.state.graceUntil)
    return null
  const remaining = Date.parse(single.state.graceUntil) - nowMs
  return (
    <div
      style={{
        borderLeft: '3px solid var(--sc-support-warning)',
        background: 'var(--cds-layer-accent)',
        padding: '10px 12px',
        marginBottom: 16,
        fontSize: 13,
      }}
    >
      This deployment is in grace with {countdownShort(remaining)} remaining.
      Suspending now skips the deadline.
    </div>
  )
}

// --- Suspend (danger, reversible → no typed confirm) ---------------------
export function SuspendModal({
  open,
  targets,
  onClose,
  onConfirm,
}: {
  open: boolean
  targets: Deployment[]
  onClose: () => void
  onConfirm: (reason: StateReason, message: string) => void
}) {
  const [reason, setReason] = useState<StateReason>('payment_overdue')
  const [message, setMessage] = useState('')
  const single = targets.length === 1 ? targets[0] : null

  useEffect(() => {
    if (open) {
      setReason(
        single && single.state.reason !== 'manual'
          ? single.state.reason
          : 'payment_overdue',
      )
      setMessage(single?.state.message || '')
    }
  }, [open, single])

  if (targets.length === 0) return null
  return (
    <Modal
      danger
      open={open}
      size="sm"
      modalLabel={targetLabel(targets)}
      modalHeading={
        single
          ? `Suspend deployment ${single.name}?`
          : `Suspend ${targets.length} deployments?`
      }
      primaryButtonText={single ? 'Suspend deployment' : 'Suspend all'}
      secondaryButtonText="Cancel"
      onRequestClose={onClose}
      onRequestSubmit={() => onConfirm(reason, message)}
    >
      <p style={{ marginBottom: 16 }}>
        The API stops serving immediately. All users of{' '}
        {single ? 'this deployment' : 'these deployments'} will receive{' '}
        <strong>HTTP 402 Payment Required</strong> until resumed.
      </p>
      <GraceContextBlock targets={targets} />
      <Select
        id="suspend-reason"
        labelText="Reason *"
        helperText="Stored on the state record and shown in the activity feed."
        value={reason}
        onChange={(e) => setReason(e.target.value as StateReason)}
      >
        {REASONS.map((r) => (
          <SelectItem key={r} value={r} text={r} />
        ))}
      </Select>
      <div style={{ height: 16 }} />
      <TextArea
        id="suspend-message"
        labelText="Client-facing message"
        helperText="Returned in the 402 response body."
        enableCounter
        maxCount={280}
        value={message}
        onChange={(e) => setMessage(e.target.value)}
        rows={3}
      />
    </Modal>
  )
}

// --- Grace (primary; deadline picker defaults to +72h) -------------------
export function GraceModal({
  open,
  targets,
  mode = 'grace',
  onClose,
  onConfirm,
}: {
  open: boolean
  targets: Deployment[]
  mode?: 'grace' | 'extend'
  onClose: () => void
  onConfirm: (reason: StateReason, message: string, graceUntilIso: string) => void
}) {
  const [reason, setReason] = useState<StateReason>('payment_overdue')
  const [message, setMessage] = useState('')
  const [date, setDate] = useState<Date | null>(null)
  const [time, setTime] = useState('14:02')
  const single = targets.length === 1 ? targets[0] : null

  useEffect(() => {
    if (open) {
      setReason(single ? single.state.reason : 'manual')
      setMessage(single?.state.message || '')
      const plus72 = new Date(simNowMs() + 72 * 3600 * 1000)
      setDate(plus72)
      setTime(
        `${String(plus72.getUTCHours()).padStart(2, '0')}:${String(
          plus72.getUTCMinutes(),
        ).padStart(2, '0')}`,
      )
    }
  }, [open, single])

  const buildIso = (): string => {
    const base = date ?? new Date(simNowMs() + 72 * 3600 * 1000)
    const [h, m] = time.split(':').map((n) => parseInt(n, 10))
    return new Date(
      Date.UTC(
        base.getUTCFullYear(),
        base.getUTCMonth(),
        base.getUTCDate(),
        isNaN(h) ? 14 : h,
        isNaN(m) ? 2 : m,
        0,
      ),
    ).toISOString()
  }

  if (targets.length === 0) return null
  const iso = date ? buildIso() : ''
  return (
    <Modal
      open={open}
      size="sm"
      modalLabel={targetLabel(targets)}
      modalHeading={
        mode === 'extend' && single
          ? `Extend grace for ${single.name}`
          : single
            ? `Move ${single.name} to grace?`
            : `Move ${targets.length} deployments to grace?`
      }
      primaryButtonText={mode === 'extend' ? 'Extend grace' : 'Move to grace'}
      secondaryButtonText="Cancel"
      onRequestClose={onClose}
      onRequestSubmit={() => onConfirm(reason, message, buildIso())}
    >
      <p style={{ marginBottom: 16 }}>
        The API keeps serving until the deadline, then auto-suspends. Users see a
        warning header until then.
      </p>
      <Select
        id="grace-reason"
        labelText="Reason *"
        value={reason}
        onChange={(e) => setReason(e.target.value as StateReason)}
      >
        {REASONS.map((r) => (
          <SelectItem key={r} value={r} text={r} />
        ))}
      </Select>
      <div style={{ height: 16 }} />
      <div style={{ display: 'flex', gap: 12, alignItems: 'flex-end' }}>
        <DatePicker
          datePickerType="single"
          dateFormat="Y-m-d"
          value={date ?? undefined}
          onChange={(d: Date[]) => setDate(d[0] ?? null)}
        >
          <DatePickerInput
            id="grace-deadline-date"
            labelText="Deadline (UTC) *"
            placeholder="yyyy-mm-dd"
          />
        </DatePicker>
        <TimePicker
          id="grace-deadline-time"
          labelText="Time"
          value={time}
          onChange={(e) => setTime(e.target.value)}
          style={{ width: 96 }}
        />
      </div>
      {iso && (
        <p
          className="sc-mono"
          style={{ fontSize: 11, color: 'var(--cds-text-helper)', marginTop: 8 }}
        >
          Suspends at {dateTimeUTC(iso)}
        </p>
      )}
      <div style={{ height: 16 }} />
      <TextArea
        id="grace-message"
        labelText="Client-facing message"
        helperText="Returned with the HTTP 402 after the deadline."
        enableCounter
        maxCount={280}
        value={message}
        onChange={(e) => setMessage(e.target.value)}
        rows={2}
      />
    </Modal>
  )
}

// --- Resume (primary confirm) -------------------------------------------
export function ResumeModal({
  open,
  targets,
  onClose,
  onConfirm,
}: {
  open: boolean
  targets: Deployment[]
  onClose: () => void
  onConfirm: () => void
}) {
  if (targets.length === 0) return null
  const single = targets.length === 1 ? targets[0] : null
  return (
    <Modal
      open={open}
      size="sm"
      modalLabel={targetLabel(targets)}
      modalHeading={
        single
          ? `Resume deployment ${single.name}?`
          : `Resume ${targets.length} deployments?`
      }
      primaryButtonText="Resume deployment"
      secondaryButtonText="Cancel"
      onRequestClose={onClose}
      onRequestSubmit={onConfirm}
    >
      <p>
        The API starts serving immediately and stops returning HTTP 402. Any
        active grace deadline is cleared.
      </p>
    </Modal>
  )
}

// --- Resume offer after marking an invoice paid --------------------------
export function ResumeOfferModal({
  open,
  deployment,
  onClose,
  onConfirm,
}: {
  open: boolean
  deployment: Deployment | null
  onClose: () => void
  onConfirm: () => void
}) {
  if (!deployment) return null
  return (
    <Modal
      open={open}
      size="sm"
      modalLabel="Payment received"
      modalHeading="Resume the suspended deployment?"
      primaryButtonText="Resume deployment"
      secondaryButtonText="Not now"
      onRequestClose={onClose}
      onRequestSubmit={onConfirm}
    >
      <p>
        <Mono>{deployment.name}</Mono> was suspended for{' '}
        <Mono>payment_overdue</Mono>. Now that the invoice is paid, you can
        restore service.
      </p>
    </Modal>
  )
}
