import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { DeploymentStatusTag, BillingTag } from './tags/StatusTag'

function state(status: 'active' | 'grace' | 'suspended', grace_until: string | null = null) {
  return { status, reason: null, message: null, grace_until, changed_by: 'cli' as const, changed_at: new Date().toISOString() }
}

describe('DeploymentStatusTag', () => {
  it('maps status to label', () => {
    render(<DeploymentStatusTag state={state('active')} />)
    expect(screen.getByText('Operational')).toBeInTheDocument()
  })
  it('suspended', () => {
    render(<DeploymentStatusTag state={state('suspended')} />)
    expect(screen.getByText('Suspended')).toBeInTheDocument()
  })
  it('grace shows a countdown', () => {
    render(<DeploymentStatusTag state={state('grace', new Date(Date.now() + 3_600_000).toISOString())} />)
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
