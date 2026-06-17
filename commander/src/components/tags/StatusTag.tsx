import { Tag } from '@carbon/react'
import type { BillingStatus, ClientStatus, DeploymentState } from '../../store/types'
import { AmberTag } from './AmberTag'
import { GraceCountdown } from '../GraceCountdown'

// Deployment lifecycle: active → green "Operational", grace → amber w/ live
// countdown, suspended → red "Suspended".
export function DeploymentStatusTag({ state }: { state: DeploymentState }) {
  if (state.status === 'active') {
    return (
      <Tag type="green" size="sm">
        Operational
      </Tag>
    )
  }
  if (state.status === 'suspended') {
    return (
      <Tag type="red" size="sm">
        Suspended
      </Tag>
    )
  }
  if (state.status === 'provisioning') {
    return (
      <Tag type="blue" size="sm">
        Provisioning
      </Tag>
    )
  }
  return (
    <AmberTag small>
      Grace · {state.grace_until ? <GraceCountdown until={state.grace_until} /> : '—'}
    </AmberTag>
  )
}

export function BillingTag({ status }: { status: BillingStatus }) {
  if (status === 'paid')
    return (
      <Tag type="green" size="sm">
        Paid
      </Tag>
    )
  if (status === 'overdue')
    return (
      <Tag type="red" size="sm">
        Overdue
      </Tag>
    )
  return <AmberTag small>Pending</AmberTag>
}

export function ClientStatusTag({ status }: { status: ClientStatus }) {
  return (
    <Tag type={status === 'active' ? 'green' : 'gray'} size="sm">
      {status === 'active' ? 'Active' : 'Inactive'}
    </Tag>
  )
}
