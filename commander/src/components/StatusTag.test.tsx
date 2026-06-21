import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PackageStatusTag, BillingTag } from './tags/StatusTag'
import type { Package } from '../store/types'

function pkg(status: 'active' | 'grace' | 'suspended', grace_until: string | null = null): Package {
  const now = new Date().toISOString()
  return {
    id: 1, name: 'Shop', subdomain: 'shop', database: 'shop',
    client_id: 1, subscription_id: 1,
    status, reason: null, message: null, grace_until,
    changed_by: 'cli', changed_at: now,
    requests_24h: 0, quota_health: 0, last_seen: now,
    created_at: now, updated_at: now,
  }
}

describe('PackageStatusTag', () => {
  it('maps status to label', () => {
    render(<PackageStatusTag pkg={pkg('active')} />)
    expect(screen.getByText('Operational')).toBeInTheDocument()
  })
  it('suspended', () => {
    render(<PackageStatusTag pkg={pkg('suspended')} />)
    expect(screen.getByText('Suspended')).toBeInTheDocument()
  })
  it('grace shows a countdown', () => {
    render(<PackageStatusTag pkg={pkg('grace', new Date(Date.now() + 3_600_000).toISOString())} />)
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
