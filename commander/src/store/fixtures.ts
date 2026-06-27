// The one coherent demo dataset shared across every screen. Built relative to a
// `nowMs` anchor (app-load time) so grace countdowns tick live and relative ages
// ("3h ago", "9d overdue") stay correct. Historical dates (created_at) are absolute.
//
// Tabla unica: cada package es UN objeto `Package` (catalogo + operativo). En el demo todos estan
// provisionados (provisioned_at != null). Las relaciones van por `package_id`.
//
// Narrative: acme-shop auto-suspended over overdue INV-2026-0139; kestrel-track in
// grace (payment_overdue) ~13h42m left; quill-cms suspended for abuse; tetra-telemetry
// in manual grace ~61h08m; helios-meter 124% over requests_day (policy warn → 429s).

import type {
  ActivityEvent,
  Client,
  Invoice,
  LogEntry,
  OperatorSettings,
  Package,
  Plan,
  QuotaMetric,
  RecentCommit,
  Subscription,
} from './types'

const S = 1000
const M = 60 * S
const H = 60 * M
const D = 24 * H

export interface Dataset {
  clients: Client[]
  packages: Package[]
  subscriptions: Subscription[]
  invoices: Invoice[]
  quotas: QuotaMetric[]
  plans: Plan[]
  logs: LogEntry[]
  activity: ActivityEvent[]
  commits: RecentCommit[]
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

// handle (kebab subdomain) → StudlyCase package name, e.g. 'acme-shop' → 'AcmeShop'.
function studly(handle: string): string {
  return handle
    .split(/[-_]/)
    .filter(Boolean)
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join('')
}

export function buildFixtures(nowMs: number): Dataset {
  const ago = (ms: number) => new Date(nowMs - ms).toISOString()
  const ahead = (ms: number) => new Date(nowMs + ms).toISOString()

  const clients: Client[] = [
    {
      id: 1,
      slug: 'acme',
      name: 'Acme Shop',
      company: 'Acme Retail GmbH',
      email: 'billing@acme-shop.de',
      phone: '+49 30 1234 5678',
      notes: 'Auto-suspend enabled. Primary contact prefers email.',
      status: 'active',
      created_at: '2024-03-04T10:00:00Z',
    },
    {
      id: 2,
      slug: 'bluepeak',
      name: 'Bluepeak Analytics',
      company: 'Bluepeak Analytics Ltd',
      email: 'dev@bluepeak.io',
      phone: '+44 20 7946 0991',
      notes: 'High-volume metrics workload across EU + US regions.',
      status: 'active',
      created_at: '2024-08-19T10:00:00Z',
    },
    {
      id: 3,
      slug: 'helios',
      name: 'Helios Energy',
      company: 'Helios Energy AG',
      email: 'api@helios.energy',
      phone: '+41 44 668 1800',
      notes: 'Telemetry bursts at hour boundaries. Watch requests_day.',
      status: 'active',
      created_at: '2025-05-12T10:00:00Z',
    },
    {
      id: 4,
      slug: 'kestrel',
      name: 'Kestrel Logistics',
      company: 'Kestrel Logistics Co',
      email: 'it@kestrel-logistics.com',
      phone: '+1 312 555 0142',
      notes: 'Payment for INV-2026-0140 outstanding — in grace.',
      status: 'active',
      created_at: '2024-11-12T10:00:00Z',
    },
    {
      id: 5,
      slug: 'mori',
      name: 'Mori Design Co',
      company: 'Mori Design Co',
      email: 'studio@mori.design',
      status: 'active',
      created_at: '2025-02-08T10:00:00Z',
    },
    {
      id: 6,
      slug: 'northwind',
      name: 'Northwind Labs',
      company: 'Northwind Labs Inc',
      email: 'ops@northwindlabs.io',
      phone: '+1 415 555 0177',
      notes: 'Three packages: prod ledger, batch runner, sandbox.',
      status: 'active',
      created_at: '2024-01-22T10:00:00Z',
    },
    {
      id: 7,
      slug: 'quill',
      name: 'Quill & Co',
      company: 'Quill & Co Publishing',
      email: 'hello@quillandco.com',
      notes: 'Suspended for abuse. Account under review.',
      status: 'inactive',
      created_at: '2023-06-15T10:00:00Z',
    },
    {
      id: 8,
      slug: 'tetra',
      name: 'Tetra Robotics',
      company: 'Tetra Robotics SAS',
      email: 'eng@tetrarobotics.com',
      phone: '+33 1 70 18 99 00',
      notes: 'Fleet + telemetry. tetra-telemetry in manual grace.',
      status: 'active',
      created_at: '2025-10-03T10:00:00Z',
    },
  ]

  // slug → numeric client id (client_id FKs are bigint).
  const clientIdBySlug = new Map(clients.map((c) => [c.slug, c.id]))

  // [handle (subdomain), client, status, requests_24h, quota_health, last_seen_ms, created_at]
  type Row = [string, string, Package['status'], number, number, number, string]
  const rows: Row[] = [
    ['acme-shop', 'acme', 'suspended', 0, 0.64, 3 * H, '2024-03-06T10:00:00Z'],
    ['acme-shop-staging', 'acme', 'active', 1204, 0.12, 2 * M, '2025-09-01T10:00:00Z'],
    ['bluepeak', 'bluepeak', 'active', 121008, 0.83, 8 * S, '2024-08-20T10:00:00Z'],
    ['bluepeak-eu', 'bluepeak', 'active', 96544, 0.71, 5 * S, '2024-09-15T10:00:00Z'],
    ['helios-archive', 'helios', 'active', 412, 0.06, 4 * M, '2025-05-20T10:00:00Z'],
    ['helios-meter', 'helios', 'active', 1240332, 1.24, 2 * S, '2025-05-12T10:00:00Z'],
    ['kestrel-track', 'kestrel', 'grace', 18772, 0.44, 30 * S, '2024-11-12T10:00:00Z'],
    ['mori-assets', 'mori', 'active', 9341, 0.22, 1 * M, '2025-02-10T10:00:00Z'],
    ['northwind-api', 'northwind', 'active', 84210, 0.58, 12 * S, '2024-01-25T10:00:00Z'],
    ['northwind-batch', 'northwind', 'active', 6902, 0.31, 45 * S, '2024-04-01T10:00:00Z'],
    ['northwind-sandbox', 'northwind', 'active', 2318, 0.09, 70 * S, '2024-06-01T10:00:00Z'],
    ['quill-cms', 'quill', 'suspended', 0, 0.38, 2 * D, '2023-06-20T10:00:00Z'],
    ['tetra-fleet', 'tetra', 'active', 54109, 0.49, 10 * S, '2025-10-05T10:00:00Z'],
    ['tetra-fleet-dev', 'tetra', 'active', 870, 0.04, 3 * M, '2025-11-20T10:00:00Z'],
    ['tetra-telemetry', 'tetra', 'grace', 22450, 0.52, 18 * S, '2025-10-10T10:00:00Z'],
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

  // Each row → one unified package (commander_packages): catalogo + operativo. Todos provisionados.
  const clientById = new Map(clients.map((c) => [c.id, c]))
  const packages: Package[] = rows.map((r, idx) => {
    const [handle, client, status, req, health, seenMs, created] = r
    let reason: Package['reason'] = null
    let message: string | null = null
    let grace_until: string | null = null
    let changed_at: string | null = created
    if (status === 'suspended') {
      reason = handle === 'quill-cms' ? 'abuse' : 'payment_overdue'
      message = handle === 'quill-cms' ? 'This package has been suspended pending account review.' : SUSPEND_MSG
      changed_at = ago(STATE_CHANGED[handle] ?? H)
    } else if (status === 'grace') {
      reason = handle === 'tetra-telemetry' ? 'manual' : 'payment_overdue'
      message = GRACE_MSG
      changed_at = ago(STATE_CHANGED[handle] ?? H)
      grace_until = ahead(GRACE_REMAINING[handle] ?? 72 * H)
    }
    const clientId = clientIdBySlug.get(client)!
    return {
      id: idx + 1, // bigint PK
      name: studly(handle), // StudlyCase package name (1:1)
      // ── catalogo ──
      label: studly(handle),
      description: null,
      listing_status: 'active',
      on_disk: true,
      plan_count: 0, // asignado tras construir los planes
      // ── operativo (provisionado) ──
      provisioned_at: created,
      slug: handle, // slug del PATH (segmento /api/v1/<slug>); el host deriva de client.slug
      database: handle.replace(/-/g, '_'),
      client_id: clientId,
      subscription_id: null, // bigint FK, asignado tras construir las subscriptions
      status,
      reason,
      message,
      grace_until,
      changed_at,
      requests_24h: req,
      quota_health: health,
      last_seen: ago(seenMs),
      created_at: created,
      updated_at: changed_at ?? created,
      // Relación cliente cargada (la API la incluye siempre; el host = client.slug).
      client: clientById.get(clientId) ?? null,
    }
  })

  // slug (path) → numeric package id (FKs son bigint)
  const pkgIdBySlug = new Map(packages.map((p) => [p.slug!, p.id]))

  // ── Subscriptions (one per billable package) ─────────────────────────────────
  const subSpec: Array<[string, string, string, number, boolean]> = [
    // package (subdomain), plan, billing_status, price, auto_suspend
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
  const subscriptions: Subscription[] = subSpec.map(([sub, plan, billing, price, auto], idx) => {
    const p = packages.find((x) => x.slug === sub)!
    return {
      id: idx + 1, // bigint PK
      client_id: p.client_id!,
      package_id: p.id,
      plan_id: null, // snapshot manda; en el demo no enlazamos al catálogo
      plan,
      price_monthly: price,
      billing_status: billing as Subscription['billing_status'],
      auto_suspend: auto,
      started_at: p.created_at,
      ended_at: null, // término vigente
    }
  })

  // Un término PASADO (historial) para kestrel-track: estuvo en un plan más barato y cambió.
  // El cerrado (ended_at no nulo) NO cuenta para el MRR y aparece en "Plan history".
  const kestrelId = pkgIdBySlug.get('kestrel-track')!
  subscriptions.push({
    id: subscriptions.length + 1,
    client_id: clientIdBySlug.get('kestrel')!,
    package_id: kestrelId,
    plan_id: null,
    plan: 'Tracking Basic',
    price_monthly: 90,
    billing_status: 'paid',
    auto_suspend: false,
    started_at: ago(400 * D),
    ended_at: ago(120 * D),
  })

  // Enlaza cada package con su término VIGENTE (ended_at null) por package_id.
  const subIdByPackageId = new Map(
    subscriptions.filter((sub) => sub.ended_at == null).map((sub) => [sub.package_id, sub.id]),
  )
  for (const p of packages) {
    p.subscription_id = subIdByPackageId.get(p.id) ?? null
  }

  // ── Invoices ─────────────────────────────────────────────────────────────────
  // [number, client, package (subdomain), amount, issued_ago_d, due_ago_d(+=overdue,-=future), status, enforcement]
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
  const invoices: Invoice[] = invSpec.map(([number, client, sub, amount, issuedD, dueD, status, enforcement], idx) => ({
    id: idx + 1, // bigint PK
    number, // handle legible 'INV-...' (antes era la PK)
    client_id: clientIdBySlug.get(client)!,
    package_id: pkgIdBySlug.get(sub)!,
    amount,
    issued_at: ago(issuedD * D),
    due_at: ago(dueD * D),
    status,
    enforcement,
  }))

  // invoice number ('INV-...') → numeric invoice id (invoice_id FKs are bigint).
  const invoiceIdByNumber = new Map(invoices.map((inv) => [inv.number, inv.id]))

  // ── Quotas (4 metrics per package) ───────────────────────────────────────────
  const quotas: QuotaMetric[] = []
  const roundTo = (v: number, step: number) => Math.max(step, Math.round(v / step) * step)
  for (const p of packages) {
    const h = p.quota_health
    const serving = p.requests_24h > 0
    if (p.slug === 'kestrel-track') {
      quotas.push(
        q(p.id, 'requests_day', 201330, 500000, 'suspend', null, 'count'),
        q(p.id, 'disk_db_mb', 1204, 4096, 'suspend', null, 'mb'),
        q(p.id, 'disk_storage_mb', 8920, 20480, 'none', null, 'mb'),
        q(p.id, 'users_total', 322, null, 'none', null, 'count'),
      )
      continue
    }
    if (p.slug === 'helios-meter') {
      quotas.push(
        q(p.id, 'requests_day', 1240332, 1000000, 'warn', ago(3 * D), 'count'),
        q(p.id, 'disk_db_mb', 7420, 10240, 'suspend', null, 'mb'),
        q(p.id, 'disk_storage_mb', 12880, 20480, 'none', null, 'mb'),
        q(p.id, 'users_total', 1840, null, 'none', null, 'count'),
      )
      continue
    }
    // requests_day: dominant when serving (ratio == health), else 0 usage
    const reqLimit = serving ? roundTo(p.requests_24h / Math.max(h, 0.01), 1000) : 500000
    quotas.push(q(p.id, 'requests_day', serving ? p.requests_24h : 0, reqLimit, 'suspend', null, 'count'))
    // disk_db_mb
    const dbLimit = 4096
    quotas.push(q(p.id, 'disk_db_mb', Math.round(clamp(h * 0.55, 0.03, 0.95) * dbLimit), dbLimit, 'suspend', null, 'mb'))
    // disk_storage_mb: dominant when suspended (ratio == health)
    const stLimit = 20480
    const stRatio = serving ? clamp(h * 0.8, 0.04, 0.98) : h
    quotas.push(q(p.id, 'disk_storage_mb', Math.round(stRatio * stLimit), stLimit, 'none', null, 'mb'))
    // users_total: unlimited for most
    const usersLimited = ['bluepeak', 'northwind-api', 'tetra-fleet'].includes(p.slug!)
    quotas.push(
      q(
        p.id,
        'users_total',
        Math.round(200 + h * 1500),
        usersLimited ? 5000 : null,
        'none',
        null,
        'count',
      ),
    )
  }
  // Reasigna PKs numéricas secuenciales (1,2,3…) a las quotas (antes id compuesto string)
  quotas.forEach((quota, idx) => {
    quota.id = idx + 1
  })

  // ── Request logs (recent sample; live tail prepends more) ─────────────────────
  // El cliente (host) de cada log se deriva del package vía package_id; lo enriquecemos aquí.
  const clientSlugByPkgId = new Map(
    packages.map((p) => [p.id, clientById.get(p.client_id!)?.slug ?? null]),
  )
  const logFor = (
    slug: string,
    method: LogEntry['method'],
    route: string,
    status: number,
    duration_ms: number,
    user_id: string | null,
    token_id: string,
    ip: string,
    request_bytes: number,
    response_bytes: number,
    created_at: string,
  ): LogEntry =>
    log(
      pkgIdBySlug.get(slug)!,
      slug,
      clientSlugByPkgId.get(pkgIdBySlug.get(slug)!) ?? undefined,
      method,
      route,
      status,
      duration_ms,
      user_id,
      token_id,
      ip,
      request_bytes,
      response_bytes,
      created_at,
    )
  const logs: LogEntry[] = [
    logFor('kestrel-track', 'GET', '/v1/track/SHP-88412', 200, 124, 'usr_8841', 'tok_live_2a18', '84.12.9.51', 412, 1224, ago(1 * S)),
    logFor('helios-meter', 'POST', '/v1/telemetry/batch', 429, 8, 'usr_502', 'tok_live_9c44', '52.28.114.9', 18842, 312, ago(3 * S)),
    logFor('bluepeak', 'GET', '/v1/metrics/query?window=1h', 200, 388, 'usr_2210', 'tok_live_77b1', '18.196.4.2', 980, 10044, ago(5 * S)),
    logFor('acme-shop-staging', 'POST', '/v1/orders', 201, 242, 'usr_1108', 'tok_live_3d90', '91.0.4.22', 2410, 612, ago(6 * S)),
    logFor('tetra-fleet', 'GET', '/v1/fleet/status', 200, 96, 'usr_3306', 'tok_live_ff21', '212.8.9.1', 310, 3140, ago(8 * S)),
    logFor('kestrel-track', 'POST', '/v1/routes/optimize', 500, 1840, 'usr_2188', 'tok_live_0a7c', '84.12.9.77', 6220, 488, ago(11 * S)),
    logFor('mori-assets', 'GET', '/v1/assets/a8f3.png', 304, 12, 'usr_410', 'tok_live_b210', '34.96.1.4', 0, 312, ago(14 * S)),
    logFor('northwind-api', 'POST', '/v1/auth/token', 401, 88, null, 'tok_live_c0de', '203.0.113.5', 612, 188, ago(17 * S)),
    logFor('northwind-api', 'GET', '/v1/ledger/entries?from=2026-06-01', 200, 154, 'usr_5521', 'tok_live_aa01', '203.0.113.9', 480, 14600, ago(21 * S)),
    logFor('helios-meter', 'POST', '/v1/telemetry/batch', 429, 7, 'usr_502', 'tok_live_9c44', '52.28.114.9', 18112, 312, ago(24 * S)),
    logFor('kestrel-track', 'GET', '/v1/track/SHP-88409', 200, 132, 'usr_8841', 'tok_live_2a18', '84.12.9.51', 412, 1208, ago(27 * S)),
    logFor('bluepeak', 'GET', '/v1/metrics/query?window=24h', 200, 401, 'usr_2231', 'tok_live_77b1', '18.196.4.9', 1020, 10240, ago(31 * S)),
    logFor('tetra-fleet', 'POST', '/v1/fleet/telemetry', 200, 73, 'usr_3310', 'tok_live_ff21', '212.8.9.4', 4400, 220, ago(38 * S)),
    logFor('bluepeak-eu', 'GET', '/v1/metrics/query?window=1h', 200, 366, 'usr_9001', 'tok_live_e2f0', '3.120.0.7', 940, 9210, ago(44 * S)),
  ]

  // ── Activity feed ────────────────────────────────────────────────────────────
  const activity: ActivityEvent[] = [
    { id: 1, created_at: ago(3 * H), kind: 'suspend', package_id: pkgIdBySlug.get('acme-shop'), invoice_id: invoiceIdByNumber.get('INV-2026-0139'), reason: 'payment_overdue' },
    { id: 2, created_at: ago(14 * H), kind: 'grace', package_id: pkgIdBySlug.get('kestrel-track'), reason: 'payment_overdue' },
    { id: 3, created_at: ago(1 * D), kind: 'paid', package_id: pkgIdBySlug.get('mori-assets'), invoice_id: invoiceIdByNumber.get('INV-2026-0141'), message: 'Mori Design Co · $120.00' },
    { id: 4, created_at: ago(26 * H), kind: 'grace', package_id: pkgIdBySlug.get('tetra-telemetry'), reason: 'manual' },
    { id: 5, created_at: ago(2 * D), kind: 'suspend', package_id: pkgIdBySlug.get('quill-cms'), reason: 'abuse' },
    { id: 6, created_at: ago(3 * D), kind: 'quota', package_id: pkgIdBySlug.get('helios-meter'), message: 'exceeded requests_day · policy warn' },
  ]

  const settings: OperatorSettings = {
    default_grace_hours: 72,
    operator_name: 'Riley Okafor',
    operator_email: 'ops@snakecloud.io',
    notify_on_suspend: true,
    notify_on_overdue: true,
  }

  const plans: Plan[] = [
    { id: 1, slug: 'commerce-pro', package: 'AcmeShop', name: 'Commerce Pro', price_monthly: 290, q_requests_day: 500000, q_disk_db_mb: 4096, q_disk_storage_mb: 20480, q_users_total: null, status: 'active' },
    { id: 2, slug: 'metrics-scale', package: 'Bluepeak', name: 'Metrics Scale', price_monthly: 480, q_requests_day: 2000000, q_disk_db_mb: 10240, q_disk_storage_mb: 51200, q_users_total: 5000, status: 'active' },
  ]

  // plan_count por package (catalogo).
  const planCountByPackage = new Map<string, number>()
  for (const pl of plans) planCountByPackage.set(pl.package, (planCountByPackage.get(pl.package) ?? 0) + 1)
  for (const p of packages) p.plan_count = planCountByPackage.get(p.name) ?? 0

  return {
    clients,
    packages,
    subscriptions,
    invoices,
    quotas,
    plans,
    logs,
    activity,
    commits: [],
    settings,
    dashboard: {
      requests_24h: packages.reduce((s, p) => s + p.requests_24h, 0),
      requests_delta: 0.12,
      error_rate: 0.018,
      error_rate_delta: 0.006,
      mrr: 3420,
      mrr_delta: 180,
    },
  }
}

function q(
  package_id: number,
  metric_key: QuotaMetric['metric_key'],
  current_value: number,
  limit_value: number | null,
  on_exceed: QuotaMetric['on_exceed'],
  exceeded_at: string | null,
  unit: QuotaMetric['unit'],
): QuotaMetric {
  return {
    id: 0, // PK bigint placeholder; reasignado secuencialmente tras construir el array
    package_id,
    metric_key,
    current_value,
    limit_value,
    on_exceed,
    exceeded_at,
    unit,
  }
}

function log(
  package_id: number,
  slug: string,
  subdomain: string | undefined,
  method: LogEntry['method'],
  route: string,
  status: number,
  duration_ms: number,
  user_id: string | null,
  token_id: string,
  ip: string,
  request_bytes: number,
  response_bytes: number,
  created_at: string,
): LogEntry {
  return {
    id: `log_${created_at}_${route}`,
    package_id,
    created_at,
    package: studly(slug), // enriched: package.name
    slug, // enriched: package.slug (path)
    subdomain, // enriched: client.slug (host)
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

