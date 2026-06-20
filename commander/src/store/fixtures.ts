// The one coherent demo dataset shared across every screen. Built relative to a
// `nowMs` anchor (app-load time) so grace countdowns tick live and relative ages
// ("3h ago", "9d overdue") stay correct. Historical dates (created_at) are absolute.
//
// Narrative: acme-shop auto-suspended over overdue INV-2026-0139; kestrel-track in
// grace (payment_overdue) ~13h42m left; quill-cms suspended for abuse; tetra-telemetry
// in manual grace ~61h08m; helios-meter 124% over requests_day (policy warn → 429s).

import type {
  ActivityEvent,
  Client,
  Deployment,
  Invoice,
  LogEntry,
  OperatorSettings,
  Plan,
  QuotaMetric,
  RegisteredPackage,
  Subscription,
  TopEndpoint,
} from './types'

const S = 1000
const M = 60 * S
const H = 60 * M
const D = 24 * H

export interface Dataset {
  clients: Client[]
  deployments: Deployment[]
  subscriptions: Subscription[]
  invoices: Invoice[]
  quotas: QuotaMetric[]
  plans: Plan[]
  packages: RegisteredPackage[]
  logs: LogEntry[]
  activity: ActivityEvent[]
  settings: OperatorSettings
  dashboard: {
    requests_24h: number
    requests_delta: number
    error_rate: number
    error_rate_delta: number
    mrr: number
    mrr_delta: number
  }
}

export const DEMO_DOMAIN = 'snakecloud.io'

export function buildFixtures(nowMs: number): Dataset {
  const ago = (ms: number) => new Date(nowMs - ms).toISOString()
  const ahead = (ms: number) => new Date(nowMs + ms).toISOString()

  const clients: Client[] = [
    {
      id: 'acme',
      name: 'Acme Shop',
      company: 'Acme Retail GmbH',
      email: 'billing@acme-shop.de',
      phone: '+49 30 1234 5678',
      notes: 'Auto-suspend enabled. Primary contact prefers email.',
      status: 'active',
      created_at: '2024-03-04T10:00:00Z',
    },
    {
      id: 'bluepeak',
      name: 'Bluepeak Analytics',
      company: 'Bluepeak Analytics Ltd',
      email: 'dev@bluepeak.io',
      phone: '+44 20 7946 0991',
      notes: 'High-volume metrics workload across EU + US regions.',
      status: 'active',
      created_at: '2024-08-19T10:00:00Z',
    },
    {
      id: 'helios',
      name: 'Helios Energy',
      company: 'Helios Energy AG',
      email: 'api@helios.energy',
      phone: '+41 44 668 1800',
      notes: 'Telemetry bursts at hour boundaries. Watch requests_day.',
      status: 'active',
      created_at: '2025-05-12T10:00:00Z',
    },
    {
      id: 'kestrel',
      name: 'Kestrel Logistics',
      company: 'Kestrel Logistics Co',
      email: 'it@kestrel-logistics.com',
      phone: '+1 312 555 0142',
      notes: 'Payment for INV-2026-0140 outstanding — in grace.',
      status: 'active',
      created_at: '2024-11-12T10:00:00Z',
    },
    {
      id: 'mori',
      name: 'Mori Design Co',
      company: 'Mori Design Co',
      email: 'studio@mori.design',
      status: 'active',
      created_at: '2025-02-08T10:00:00Z',
    },
    {
      id: 'northwind',
      name: 'Northwind Labs',
      company: 'Northwind Labs Inc',
      email: 'ops@northwindlabs.io',
      phone: '+1 415 555 0177',
      notes: 'Three deployments: prod ledger, batch runner, sandbox.',
      status: 'active',
      created_at: '2024-01-22T10:00:00Z',
    },
    {
      id: 'quill',
      name: 'Quill & Co',
      company: 'Quill & Co Publishing',
      email: 'hello@quillandco.com',
      notes: 'Suspended for abuse. Account under review.',
      status: 'inactive',
      created_at: '2023-06-15T10:00:00Z',
    },
    {
      id: 'tetra',
      name: 'Tetra Robotics',
      company: 'Tetra Robotics SAS',
      email: 'eng@tetrarobotics.com',
      phone: '+33 1 70 18 99 00',
      notes: 'Fleet + telemetry. tetra-telemetry in manual grace.',
      status: 'active',
      created_at: '2025-10-03T10:00:00Z',
    },
  ]

  // [name, client, package, version, status, requests_24h, quota_health, last_seen_ms, secretSuffix, rotated_ms_ago, created_at]
  type Row = [
    string,
    string,
    string,
    string,
    Deployment['state']['status'],
    number,
    number,
    number,
    string,
    number,
    string,
  ]
  const rows: Row[] = [
    ['acme-shop', 'acme', 'commerce-api', 'v2.4.1', 'suspended', 0, 0.64, 3 * H, '9b1c', 22 * D, '2024-03-06T10:00:00Z'],
    ['acme-shop-staging', 'acme', 'commerce-api', 'v2.5.0-rc1', 'active', 1204, 0.12, 2 * M, 'a7e0', 9 * D, '2025-09-01T10:00:00Z'],
    ['bluepeak', 'bluepeak', 'metrics-api', 'v4.0.2', 'active', 121008, 0.83, 8 * S, '4d44', 12 * D, '2024-08-20T10:00:00Z'],
    ['bluepeak-eu', 'bluepeak', 'metrics-api', 'v4.0.2', 'active', 96544, 0.71, 5 * S, 'c19a', 12 * D, '2024-09-15T10:00:00Z'],
    ['helios-archive', 'helios', 'archive-api', 'v1.0.4', 'active', 412, 0.06, 4 * M, '5520', 60 * D, '2025-05-20T10:00:00Z'],
    ['helios-meter', 'helios', 'telemetry-api', 'v5.2.1', 'active', 1240332, 1.24, 2 * S, 'f0a3', 5 * D, '2025-05-12T10:00:00Z'],
    ['kestrel-track', 'kestrel', 'tracking-api', 'v2.9.3', 'grace', 18772, 0.44, 30 * S, '3f2a', 41 * D, '2024-11-12T10:00:00Z'],
    ['mori-assets', 'mori', 'asset-cdn', 'v1.2.0', 'active', 9341, 0.22, 1 * M, '7711', 30 * D, '2025-02-10T10:00:00Z'],
    ['northwind-api', 'northwind', 'ledger-api', 'v3.1.0', 'active', 84210, 0.58, 12 * S, 'b2c8', 18 * D, '2024-01-25T10:00:00Z'],
    ['northwind-batch', 'northwind', 'batch-runner', 'v1.8.2', 'active', 6902, 0.31, 45 * S, 'e4d1', 18 * D, '2024-04-01T10:00:00Z'],
    ['northwind-sandbox', 'northwind', 'ledger-api', 'v3.1.0', 'active', 2318, 0.09, 70 * S, '0c9f', 18 * D, '2024-06-01T10:00:00Z'],
    ['quill-cms', 'quill', 'content-api', 'v3.3.0', 'suspended', 0, 0.38, 2 * D, '8a20', 75 * D, '2023-06-20T10:00:00Z'],
    ['tetra-fleet', 'tetra', 'fleet-api', 'v6.0.0', 'active', 54109, 0.49, 10 * S, 'd7b6', 14 * D, '2025-10-05T10:00:00Z'],
    ['tetra-fleet-dev', 'tetra', 'fleet-api', 'v6.1.0-beta', 'active', 870, 0.04, 3 * M, '1e3c', 7 * D, '2025-11-20T10:00:00Z'],
    ['tetra-telemetry', 'tetra', 'telemetry-api', 'v5.2.1', 'grace', 22450, 0.52, 18 * S, 'aa51', 20 * D, '2025-10-10T10:00:00Z'],
  ]

  const GRACE_REMAINING: Record<string, number> = {
    'kestrel-track': 13 * H + 42 * M,
    'tetra-telemetry': 61 * H + 8 * M,
  }
  const STATE_CHANGED: Record<string, number> = {
    'acme-shop': 3 * H,
    'quill-cms': 2 * D,
    'kestrel-track': 14 * H,
    'tetra-telemetry': 26 * H,
  }
  const SUSPEND_MSG =
    'Your subscription payment is overdue. The API has been suspended — settle the open balance to restore service immediately.'
  const GRACE_MSG =
    'Your subscription payment is overdue. Service continues during a short grace period; settle the balance to avoid suspension.'

  const deployments: Deployment[] = rows.map((r) => {
    const [name, client, pkg, version, status, req, health, seenMs, , , created] = r
    let reason: string | null = null
    let message: string | null = null
    let grace_until: string | null = null
    let changed_by: Deployment['state']['changed_by'] = 'cli'
    let changed_at = created
    if (status === 'suspended') {
      reason = name === 'quill-cms' ? 'abuse' : 'payment_overdue'
      message = name === 'quill-cms' ? 'This deployment has been suspended pending account review.' : SUSPEND_MSG
      changed_by = name === 'quill-cms' ? 'operator' : 'auto-suspend'
      changed_at = ago(STATE_CHANGED[name] ?? H)
    } else if (status === 'grace') {
      reason = name === 'tetra-telemetry' ? 'manual' : 'payment_overdue'
      message = GRACE_MSG
      changed_by = 'operator'
      changed_at = ago(STATE_CHANGED[name] ?? H)
      grace_until = ahead(GRACE_REMAINING[name] ?? 72 * H)
    }
    return {
      id: name,
      name,
      subdomain: `${name}.${DEMO_DOMAIN}`,
      client_id: client,
      package: pkg,
      version,
      state: { status, reason, message, grace_until, changed_by, changed_at },
      requests_24h: req,
      quota_health: health,
      last_seen: ago(seenMs),
      base_url: `https://${name}.${DEMO_DOMAIN}/api/v1`,
      subscription_id: `sub_${name}`,
      created_at: created,
    }
  })

  // ── Subscriptions (one per billable deployment) ──────────────────────────────
  const subSpec: Array<[string, string, string, number, boolean]> = [
    // deployment, plan, billing_status, price, auto_suspend
    ['acme-shop', 'Commerce Pro', 'overdue', 290, true],
    ['bluepeak', 'Metrics Scale', 'paid', 480, false],
    ['helios-meter', 'Telemetry Pro', 'pending', 520, false],
    ['kestrel-track', 'Tracking Pro', 'overdue', 180, true],
    ['mori-assets', 'CDN Basic', 'paid', 120, false],
    ['northwind-api', 'Ledger Pro', 'paid', 310, false],
    ['tetra-fleet', 'Fleet Pro', 'pending', 390, false],
    ['quill-cms', 'Content Standard', 'paid', 150, true],
    ['bluepeak-eu', 'Metrics Scale', 'paid', 480, false],
    ['tetra-telemetry', 'Telemetry Standard', 'paid', 260, false],
  ]
  const subscriptions: Subscription[] = subSpec.map(([dep, plan, billing, price, auto]) => {
    const dpl = deployments.find((d) => d.id === dep)!
    return {
      id: `sub_${dep}`,
      client_id: dpl.client_id,
      deployment_id: dep,
      plan,
      price_monthly: price,
      billing_status: billing as Subscription['billing_status'],
      auto_suspend: auto,
      started_at: dpl.created_at,
    }
  })

  // ── Invoices ─────────────────────────────────────────────────────────────────
  // [number, client, deployment, amount, issued_ago_d, due_ago_d(+=overdue,-=future), status, enforcement]
  const invSpec: Array<[string, string, string, number, number, number, Invoice['status'], Invoice['enforcement']]> = [
    ['INV-2026-0145', 'helios', 'helios-meter', 520, 12, 2, 'pending', null], // 2d late, still pending
    ['INV-2026-0144', 'bluepeak', 'bluepeak', 240, 7, -17, 'pending', null],
    ['INV-2026-0143', 'tetra', 'tetra-fleet', 390, 11, -13, 'pending', null],
    ['INV-2026-0142', 'northwind', 'northwind-api', 310, 20, 6, 'paid', null],
    ['INV-2026-0141', 'mori', 'mori-assets', 120, 15, 1, 'paid', null],
    ['INV-2026-0140', 'kestrel', 'kestrel-track', 180, 17, 3, 'overdue', 'grace'],
    ['INV-2026-0139', 'acme', 'acme-shop', 290, 23, 9, 'overdue', 'suspended'],
    ['INV-2026-0138', 'helios', 'helios-meter', 520, 42, 12, 'paid', null],
  ]
  const invoices: Invoice[] = invSpec.map(([id, client, dep, amount, issuedD, dueD, status, enforcement]) => ({
    id,
    client_id: client,
    deployment_id: dep,
    amount,
    issued_at: ago(issuedD * D),
    due_at: ago(dueD * D),
    status,
    enforcement,
  }))

  // ── Quotas (4 metrics per deployment) ────────────────────────────────────────
  const quotas: QuotaMetric[] = []
  const roundTo = (v: number, step: number) => Math.max(step, Math.round(v / step) * step)
  for (const d of deployments) {
    const h = d.quota_health
    const serving = d.requests_24h > 0
    if (d.id === 'kestrel-track') {
      quotas.push(
        q(d.id, 'requests_day', 201330, 500000, 'suspend', null, 'count'),
        q(d.id, 'disk_db_mb', 1204, 4096, 'suspend', null, 'mb'),
        q(d.id, 'disk_storage_mb', 8920, 20480, 'none', null, 'mb'),
        q(d.id, 'users_total', 322, null, 'none', null, 'count'),
      )
      continue
    }
    if (d.id === 'helios-meter') {
      quotas.push(
        q(d.id, 'requests_day', 1240332, 1000000, 'warn', ago(3 * D), 'count'),
        q(d.id, 'disk_db_mb', 7420, 10240, 'suspend', null, 'mb'),
        q(d.id, 'disk_storage_mb', 12880, 20480, 'none', null, 'mb'),
        q(d.id, 'users_total', 1840, null, 'none', null, 'count'),
      )
      continue
    }
    // requests_day: dominant when serving (ratio == health), else 0 usage
    const reqLimit = serving ? roundTo(d.requests_24h / Math.max(h, 0.01), 1000) : 500000
    quotas.push(q(d.id, 'requests_day', serving ? d.requests_24h : 0, reqLimit, 'suspend', null, 'count'))
    // disk_db_mb
    const dbLimit = 4096
    quotas.push(q(d.id, 'disk_db_mb', Math.round(clamp(h * 0.55, 0.03, 0.95) * dbLimit), dbLimit, 'suspend', null, 'mb'))
    // disk_storage_mb: dominant when suspended (ratio == health)
    const stLimit = 20480
    const stRatio = serving ? clamp(h * 0.8, 0.04, 0.98) : h
    quotas.push(q(d.id, 'disk_storage_mb', Math.round(stRatio * stLimit), stLimit, 'none', null, 'mb'))
    // users_total: unlimited for most
    const usersLimited = ['bluepeak', 'northwind-api', 'tetra-fleet'].includes(d.id)
    quotas.push(
      q(
        d.id,
        'users_total',
        Math.round(200 + h * 1500),
        usersLimited ? 5000 : null,
        'none',
        null,
        'count',
      ),
    )
  }

  // ── Request logs (recent sample; live tail prepends more) ─────────────────────
  const logs: LogEntry[] = [
    log('kestrel-track', 'GET', '/v1/track/SHP-88412', 200, 124, 'usr_8841', 'tok_live_2a18', '84.12.9.51', 412, 1224, ago(1 * S)),
    log('helios-meter', 'POST', '/v1/telemetry/batch', 429, 8, 'usr_502', 'tok_live_9c44', '52.28.114.9', 18842, 312, ago(3 * S)),
    log('bluepeak', 'GET', '/v1/metrics/query?window=1h', 200, 388, 'usr_2210', 'tok_live_77b1', '18.196.4.2', 980, 10044, ago(5 * S)),
    log('acme-shop-staging', 'POST', '/v1/orders', 201, 242, 'usr_1108', 'tok_live_3d90', '91.0.4.22', 2410, 612, ago(6 * S)),
    log('tetra-fleet', 'GET', '/v1/fleet/status', 200, 96, 'usr_3306', 'tok_live_ff21', '212.8.9.1', 310, 3140, ago(8 * S)),
    log('kestrel-track', 'POST', '/v1/routes/optimize', 500, 1840, 'usr_2188', 'tok_live_0a7c', '84.12.9.77', 6220, 488, ago(11 * S)),
    log('mori-assets', 'GET', '/v1/assets/a8f3.png', 304, 12, 'usr_410', 'tok_live_b210', '34.96.1.4', 0, 312, ago(14 * S)),
    log('northwind-api', 'POST', '/v1/auth/token', 401, 88, null, 'tok_live_c0de', '203.0.113.5', 612, 188, ago(17 * S)),
    log('northwind-api', 'GET', '/v1/ledger/entries?from=2026-06-01', 200, 154, 'usr_5521', 'tok_live_aa01', '203.0.113.9', 480, 14600, ago(21 * S)),
    log('helios-meter', 'POST', '/v1/telemetry/batch', 429, 7, 'usr_502', 'tok_live_9c44', '52.28.114.9', 18112, 312, ago(24 * S)),
    log('kestrel-track', 'GET', '/v1/track/SHP-88409', 200, 132, 'usr_8841', 'tok_live_2a18', '84.12.9.51', 412, 1208, ago(27 * S)),
    log('bluepeak', 'GET', '/v1/metrics/query?window=24h', 200, 401, 'usr_2231', 'tok_live_77b1', '18.196.4.9', 1020, 10240, ago(31 * S)),
    log('tetra-fleet', 'POST', '/v1/fleet/telemetry', 200, 73, 'usr_3310', 'tok_live_ff21', '212.8.9.4', 4400, 220, ago(38 * S)),
    log('bluepeak-eu', 'GET', '/v1/metrics/query?window=1h', 200, 366, 'usr_9001', 'tok_live_e2f0', '3.120.0.7', 940, 9210, ago(44 * S)),
  ]

  // ── Activity feed ────────────────────────────────────────────────────────────
  const activity: ActivityEvent[] = [
    { id: 'a1', at: ago(3 * H), kind: 'suspend', deployment_id: 'acme-shop', invoice_id: 'INV-2026-0139', reason: 'payment_overdue', by: 'auto-suspend' },
    { id: 'a2', at: ago(14 * H), kind: 'grace', deployment_id: 'kestrel-track', reason: 'payment_overdue', by: 'operator' },
    { id: 'a3', at: ago(1 * D), kind: 'paid', deployment_id: 'mori-assets', invoice_id: 'INV-2026-0141', message: 'Mori Design Co · $120.00' },
    { id: 'a4', at: ago(26 * H), kind: 'grace', deployment_id: 'tetra-telemetry', reason: 'manual', by: 'operator' },
    { id: 'a5', at: ago(2 * D), kind: 'suspend', deployment_id: 'quill-cms', reason: 'abuse', by: 'operator' },
    { id: 'a6', at: ago(3 * D), kind: 'quota', deployment_id: 'helios-meter', message: 'exceeded requests_day · policy warn' },
  ]

  const settings: OperatorSettings = {
    default_grace_hours: 72,
    operator_name: 'Riley Okafor',
    operator_email: 'ops@snakecloud.io',
    notify_on_suspend: true,
    notify_on_overdue: true,
  }

  const plans: Plan[] = [
    { id: 'commerce-pro', package: 'commerce-api', name: 'Commerce Pro', price_monthly: 290, q_requests_day: 500000, q_disk_db_mb: 4096, q_disk_storage_mb: 20480, q_users_total: null, status: 'active' },
    { id: 'metrics-scale', package: 'metrics-api', name: 'Metrics Scale', price_monthly: 480, q_requests_day: 2000000, q_disk_db_mb: 10240, q_disk_storage_mb: 51200, q_users_total: 5000, status: 'active' },
  ]

  // Registered packages (types) derived from the demo deployments.
  const packages: RegisteredPackage[] = Array.from(new Set(deployments.map((d) => d.package))).map((name) => ({
    name,
    label: name,
    description: null,
    status: 'active' as const,
  }))

  return {
    clients,
    deployments,
    subscriptions,
    invoices,
    quotas,
    plans,
    packages,
    logs,
    activity,
    settings,
    dashboard: {
      requests_24h: deployments.reduce((s, d) => s + d.requests_24h, 0),
      requests_delta: 0.12,
      error_rate: 0.018,
      error_rate_delta: 0.006,
      mrr: 3420,
      mrr_delta: 180,
    },
  }
}

function q(
  deployment_id: string,
  metric_key: QuotaMetric['metric_key'],
  current_value: number,
  limit_value: number | null,
  on_exceed: QuotaMetric['on_exceed'],
  exceeded_at: string | null,
  unit: QuotaMetric['unit'],
): QuotaMetric {
  return {
    id: `${deployment_id}:${metric_key}`,
    deployment_id,
    metric_key,
    current_value,
    limit_value,
    on_exceed,
    exceeded_at,
    unit,
  }
}

function log(
  deployment_id: string,
  method: LogEntry['method'],
  route: string,
  status: number,
  duration_ms: number,
  user_id: string | null,
  token_id: string,
  ip: string,
  request_bytes: number,
  response_bytes: number,
  occurred_at: string,
): LogEntry {
  return {
    id: `log_${occurred_at}_${route}`,
    occurred_at,
    deployment_id,
    method,
    route,
    status,
    duration_ms,
    user_id,
    token_id,
    ip,
    request_bytes,
    response_bytes,
  }
}

function clamp(v: number, lo: number, hi: number): number {
  return Math.min(hi, Math.max(lo, v))
}

// Top endpoints per deployment for the Usage tab. kestrel-track is hand-authored
// (matches the design); others are derived deterministically from request volume.
const KESTREL_ENDPOINTS: TopEndpoint[] = [
  { route: '/v1/track/{shipment}', method: 'GET', requests: 64209, error_rate: 0.008, p95_ms: 412 },
  { route: '/v1/shipments', method: 'POST', requests: 22118, error_rate: 0.012, p95_ms: 488 },
  { route: '/v1/shipments/{id}', method: 'GET', requests: 19540, error_rate: 0.006, p95_ms: 301 },
  { route: '/v1/routes/optimize', method: 'POST', requests: 12002, error_rate: 0.041, p95_ms: 1204 },
  { route: '/v1/webhooks/delivery', method: 'POST', requests: 9310, error_rate: 0.024, p95_ms: 644 },
  { route: '/v1/auth/token', method: 'POST', requests: 6221, error_rate: 0.002, p95_ms: 188 },
]

export function topEndpointsFor(d: Deployment): TopEndpoint[] {
  if (d.id === 'kestrel-track') return KESTREL_ENDPOINTS
  const noun = d.package.replace(/-api$/, '').replace(/-/g, '/')
  const base = Math.max(d.requests_24h, 200)
  const specs: Array<[string, TopEndpoint['method'], number, number, number]> = [
    [`/v1/${noun}`, 'GET', 0.42, 0.006, 220],
    [`/v1/${noun}/{id}`, 'GET', 0.27, 0.009, 188],
    [`/v1/${noun}/query`, 'POST', 0.16, 0.014, 470],
    [`/v1/auth/token`, 'POST', 0.09, 0.003, 142],
    [`/v1/webhooks/notify`, 'POST', 0.06, 0.021, 612],
  ]
  return specs.map(([route, method, share, err, p95]) => ({
    route,
    method,
    requests: Math.round(base * 7 * share),
    error_rate: err,
    p95_ms: p95,
  }))
}
