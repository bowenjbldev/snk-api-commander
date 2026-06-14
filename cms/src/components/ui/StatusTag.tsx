import type { CSSProperties, ReactNode } from 'react'
import { Tag } from '@carbon/react'
import type {
  ClientStatus,
  DeploymentState,
  InvoiceStatus,
} from '../../types'
import { useNowMs } from '../../lib/time'
import { countdownShort } from '../../lib/format'

// Carbon has no yellow/amber Tag — keep the design's custom warm-amber pill.
export function AmberTag({
  children,
  size = 'md',
  title,
}: {
  children: ReactNode
  size?: 'sm' | 'md'
  title?: string
}) {
  const style: CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    height: size === 'sm' ? 18 : 24,
    padding: '0 8px',
    borderRadius: 24,
    fontSize: 12,
    lineHeight: 1,
    whiteSpace: 'nowrap',
    background: 'var(--sc-tag-amber-bg)',
    color: 'var(--sc-tag-amber-tx)',
  }
  return (
    <span style={style} title={title} className="sc-amber-tag">
      {children}
    </span>
  )
}

/** Operational (green) / Grace · countdown (amber, live) / Suspended (red). */
export function DeploymentStatusTag({
  state,
  size = 'md',
}: {
  state: DeploymentState
  size?: 'sm' | 'md'
}) {
  const nowMs = useNowMs()
  if (state.status === 'active') {
    return (
      <Tag type="green" size={size}>
        Operational
      </Tag>
    )
  }
  if (state.status === 'suspended') {
    return (
      <Tag type="red" size={size}>
        Suspended
      </Tag>
    )
  }
  // grace
  const remaining = state.graceUntil
    ? Date.parse(state.graceUntil) - nowMs
    : 0
  return (
    <AmberTag size={size} title="Time remaining until suspension">
      Grace · {countdownShort(remaining)}
    </AmberTag>
  )
}

export function BillingStatusTag({
  status,
  size = 'md',
}: {
  status: InvoiceStatus
  size?: 'sm' | 'md'
}) {
  if (status === 'paid')
    return (
      <Tag type="green" size={size}>
        Paid
      </Tag>
    )
  if (status === 'overdue')
    return (
      <Tag type="red" size={size}>
        Overdue
      </Tag>
    )
  return (
    <AmberTag size={size}>Pending</AmberTag>
  )
}

export function ClientStatusTag({
  status,
  size = 'md',
}: {
  status: ClientStatus
  size?: 'sm' | 'md'
}) {
  return status === 'active' ? (
    <Tag type="green" size={size}>
      Active
    </Tag>
  ) : (
    <Tag type="gray" size={size}>
      Inactive
    </Tag>
  )
}
