import type { ReactNode } from 'react'
import { Tag } from '@carbon/react'
import type { BillingStatus, ClientStatus, Package } from '../../store/types'
import { GraceCountdown } from '../GraceCountdown'

// Carbon ships no warm-amber tag; this matches the design tokens + pill shape.
function AmberTag({ children, small }: { children: ReactNode; small?: boolean }) {
  return <span className={`scns-tag scns-tag--amber${small ? ' scns-tag--sm' : ''}`}>{children}</span>
}

// Package lifecycle: active → green "Operational", grace → amber w/ live
// countdown, suspended → red "Suspended". Lee el estado del package provisionado (commander_packages).
export function PackageStatusTag({ pkg }: { pkg: Package }) {
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
