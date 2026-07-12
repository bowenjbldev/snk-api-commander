import type { Client, Dataset, Invoice, Package, QuotaMetric, Subscription } from './types'

const client: Client = {
  id: 1,
  slug: 'test',
  name: 'Test Client',
  company: 'Test Client',
  email: 'test@example.com',
  status: 'active',
  created_at: '2025-01-01T00:00:00Z',
}

function pkg(id: number, name: string, slug: string, status: Package['status'] = 'active'): Package {
  const created = '2025-01-01T00:00:00Z'
  return {
    id,
    name,
    label: name,
    description: null,
    listing_status: 'active',
    provisioned_at: created,
    slug,
    database: slug.replaceAll('-', '_'),
    client_id: client.id,
    subscription_id: id,
    status,
    reason: status === 'suspended' ? 'payment_overdue' : null,
    message: null,
    grace_until: null,
    changed_at: created,
    requests_24h: 100,
    quota_health: 0.5,
    last_seen: created,
    created_at: created,
    updated_at: created,
    client,
  }
}

/** Minimal reducer dataset; production never imports this file. */
export function buildFixtures(nowMs: number): Dataset {
  void nowMs
  const packages = [
    pkg(1, 'Bluepeak', 'bluepeak'),
    pkg(2, 'MoriAssets', 'mori-assets'),
    pkg(3, 'AcmeShop', 'acme-shop', 'suspended'),
  ]
  const subscriptions: Subscription[] = packages.map((p) => ({
    id: p.id,
    client_id: client.id,
    package_id: p.id,
    plan_id: null,
    plan: 'Basic',
    price_monthly: 100,
    billing_status: p.name === 'AcmeShop' ? 'overdue' : 'paid',
    auto_suspend: false,
    started_at: p.created_at,
    ended_at: null,
  }))
  const invoices: Invoice[] = [{
    id: 1,
    number: 'INV-2026-0139',
    client_id: client.id,
    package_id: 3,
    amount: 100,
    issued_at: '2026-01-01T00:00:00Z',
    due_at: '2026-01-15T00:00:00Z',
    status: 'overdue',
    enforcement: 'suspended',
  }]
  const quotas: QuotaMetric[] = [{
    id: 1,
    package_id: 1,
    metric_key: 'requests_day',
    current_value: 100,
    limit_value: 200,
    on_exceed: 'warn',
    exceeded_at: null,
    unit: 'count',
  }]
  return {
    clients: [client],
    packages,
    subscriptions,
    invoices,
    quotas,
    plans: [],
    logs: [],
    activity: [],
    commits: [],
    settings: {
      default_grace_hours: 72,
      operator_name: 'Test Operator',
      operator_email: 'operator@example.com',
      notify_on_suspend: true,
      notify_on_overdue: true,
    },
    dashboard: {
      requests_24h: 300,
      requests_delta: 0,
      error_rate: 0,
      error_rate_delta: 0,
      mrr: 300,
      mrr_delta: 0,
    },
  }
}
