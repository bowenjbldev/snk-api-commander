import { describe, it, expect, vi, beforeEach } from 'vitest'

// The store fires API calls behind its optimistic mutations; stub the client so unit tests
// exercise the local reducer logic only. Resolving to {} (no `id`) skips reconcile.
vi.mock('../lib/api', () => ({
  api: vi.fn(() => Promise.resolve({})),
  ApiError: class ApiError extends Error {},
  getToken: () => null,
  setToken: () => {},
  setUnauthorizedHandler: () => {},
  authApi: { login: vi.fn(), me: vi.fn(), logout: vi.fn() },
}))

import { useStore } from './store'
import { buildFixtures } from './fixtures'

const s = () => useStore.getState()

// The production store starts empty; seed the demo dataset before each test so the reducer logic
// has deployments/invoices/quotas to mutate.
beforeEach(() => {
  useStore.setState(buildFixtures(Date.now()))
})

describe('store mutations', () => {
  // Los ids de deployment son bigint ahora; se resuelven por su slug legible.
  const depId = (slug: string) => s().deployments.find((d) => d.slug === slug)!.id

  it('suspend → resume writes activity and flips status', () => {
    const id = depId('bluepeak')
    s().suspend(id, { reason: 'manual' })
    expect(s().deployments.find((d) => d.id === id)?.state.status).toBe('suspended')
    expect(s().activity[0]).toMatchObject({ kind: 'suspend', deployment_id: id })

    s().resume(id)
    expect(s().deployments.find((d) => d.id === id)?.state.status).toBe('active')
    expect(s().activity[0]).toMatchObject({ kind: 'resume', deployment_id: id })
  })

  it('grace sets a deadline', () => {
    const until = new Date(Date.now() + 72 * 3_600_000).toISOString()
    const id = depId('mori-assets')
    s().grace(id, { reason: 'manual', grace_until: until })
    const dep = s().deployments.find((d) => d.id === id)
    expect(dep?.state.status).toBe('grace')
    expect(dep?.state.grace_until).toBe(until)
  })

  it('markInvoicePaid reports a resumable deployment when it was suspended for payment_overdue', () => {
    const inv = s().invoices.find((i) => i.number === 'INV-2026-0139')!
    const { resumableDeploymentId } = s().markInvoicePaid(inv.id)
    expect(resumableDeploymentId).toBe(depId('acme-shop'))
    expect(s().invoices.find((i) => i.number === 'INV-2026-0139')?.status).toBe('paid')
  })

  it('editQuotaLimit below current usage stamps exceeded_at', () => {
    const q = s().quotas.find((x) => x.current_value > 0 && x.limit_value != null && x.exceeded_at == null)!
    s().editQuotaLimit(q.id, 1)
    const after = s().quotas.find((x) => x.id === q.id)!
    expect(after.limit_value).toBe(1)
    expect(after.exceeded_at).not.toBeNull()
  })
})
