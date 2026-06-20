import { Tag } from '@carbon/react'
import type { BillingStatus, ClientStatus, DeploymentPackage } from '../../store/types'
import { AmberTag } from './AmberTag'
import { GraceCountdown } from '../GraceCountdown'

// Deployment lifecycle: active → green "Operational", grace → amber w/ live
// countdown, suspended → red "Suspended". Lee el estado de la instancia `package`.
export function DeploymentStatusTag({ pkg }: { pkg: DeploymentPackage }) {
  if (pkg.status === 'active') {
    return (
      <Tag type="green" size="sm">
        Operational
      </Tag>
    )
  }
  if (pkg.status === 'suspended') {
    return (
      <Tag type="red" size="sm">
        Suspended
      </Tag>
    )
  }
  return (
    <AmberTag small>
      Grace · {pkg.grace_until ? <GraceCountdown until={pkg.grace_until} /> : '—'}
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
