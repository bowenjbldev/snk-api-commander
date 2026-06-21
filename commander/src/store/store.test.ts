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
  packagesApi: {
    list: vi.fn(() => Promise.resolve([])),
    update: vi.fn(() => Promise.resolve({})),
    suspend: vi.fn(() => Promise.resolve({})),
    grace: vi.fn(() => Promise.resolve({})),
    resume: vi.fn(() => Promise.resolve({})),
    deprovision: vi.fn(() => Promise.resolve({})),
    usage: vi.fn(() => Promise.resolve({ data: [] })),
    logs: vi.fn(() => Promise.resolve({ data: [] })),
  },
  settingsApi: { get: vi.fn(), update: vi.fn(() => Promise.resolve({})) },
}))

import { useStore } from './store'
import { buildFixtures } from './fixtures'
import type { Package } from './types'

const s = () => useStore.getState()

// The production store starts empty; seed the demo dataset before each test so the reducer logic
// has provisioned packages/invoices/quotas to mutate.
beforeEach(() => {
  useStore.setState(buildFixtures(Date.now()))
})

describe('store mutations', () => {
  // El modelo es 1:1; el package provisionado se resuelve por su subdominio legible. Las acciones de
  // estado se direccionan por `name`.
  const pkgBySub = (slug: string): Package =>
    s().packages.find((p) => p.subdomain === slug)!

  it('suspend → resume writes activity and flips status', () => {
    const pkg = pkgBySub('bluepeak')
    s().suspend(pkg.name, { reason: 'manual' })
    expect(pkgBySub('bluepeak').status).toBe('suspended')
    expect(s().activity[0]).toMatchObject({ kind: 'suspend', package_id: pkg.id })

    s().resume(pkg.name)
    expect(pkgBySub('bluepeak').status).toBe('active')
    expect(s().activity[0]).toMatchObject({ kind: 'resume', package_id: pkg.id })
  })

  it('grace sets a deadline', () => {
    const until = new Date(Date.now() + 72 * 3_600_000).toISOString()
    const pkg = pkgBySub('mori-assets')
    s().grace(pkg.name, { reason: 'manual', grace_until: until })
    const after = pkgBySub('mori-assets')
    expect(after.status).toBe('grace')
    expect(after.grace_until).toBe(until)
  })

  it('markInvoicePaid reports a resumable package when it was suspended for payment_overdue', () => {
    const inv = s().invoices.find((i) => i.number === 'INV-2026-0139')!
    const { resumablePackageName } = s().markInvoicePaid(inv.id)
    expect(resumablePackageName).toBe(pkgBySub('acme-shop').name)
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
