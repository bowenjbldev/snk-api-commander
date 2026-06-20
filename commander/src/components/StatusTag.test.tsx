import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { DeploymentStatusTag, BillingTag } from './tags/StatusTag'
import type { DeploymentPackage } from '../store/types'

function pkg(status: 'active' | 'grace' | 'suspended', grace_until: string | null = null): DeploymentPackage {
  const now = new Date().toISOString()
  return {
    id: 1, name: 'Shop', subdomain: 'shop', database: 'shop',
    status, reason: null, message: null, grace_until,
    changed_by: 'cli', changed_at: now, created_at: now, updated_at: now,
  }
}

describe('DeploymentStatusTag', () => {
  it('maps status to label', () => {
    render(<DeploymentStatusTag pkg={pkg('active')} />)
    expect(screen.getByText('Operational')).toBeInTheDocument()
  })
  it('suspended', () => {
    render(<DeploymentStatusTag pkg={pkg('suspended')} />)
    expect(screen.getByText('Suspended')).toBeInTheDocument()
  })
  it('grace shows a countdown', () => {
    render(<DeploymentStatusTag pkg={pkg('grace', new Date(Date.now() + 3_600_000).toISOString())} />)
    expect(screen.getByText(/Grace/)).toBeInTheDocument()
  })
})

describe('BillingTag', () => {
  it('maps billing status', () => {
    const { rerender } = render(<BillingTag status="paid" />)
    expect(screen.getByText('Paid')).toBeInTheDocument()
    rerender(<BillingTag status="overdue" />)
    expect(screen.getByText('Overdue')).toBeInTheDocument()
    rerender(<BillingTag status="pending" />)
    expect(screen.getByText('Pending')).toBeInTheDocument()
  })
})
