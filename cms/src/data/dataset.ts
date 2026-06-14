// The single coherent demo dataset. Every screen reads from here.
//
// Narrative (anchored at DEMO_NOW = 2026-06-11 14:02:31 UTC):
//  - acme-shop      suspended 3h ago by auto-suspend over overdue INV-2026-0139.
//  - kestrel-track  in grace (payment_overdue, INV-2026-0140), 13h 42m left.
//  - quill-cms      suspended for abuse 2d ago.
//  - tetra-telemetry in grace (manual), 61h 08m left.
//  - helios-meter   124% over requests_day (1,240,332 / 1,000,000, policy warn).
import {
  agoIso,
  aheadIso,
  HOUR,
  MIN,
  DAY,
  DEMO_NOW_ISO,
} from '../lib/time'
import type {
  ActivityEvent,
  AttentionItem,
  Client,
  DashboardKpis,
  Deployment,
  DeploymentState,
  EndpointStat,
  Invoice,
  Quota,
  RequestLog,
  Subscription,
  UsageSummary,
} from '../types'

const H = HOUR / 1000
const M = MIN / 1000
const D = DAY / 1000

// --- clients -------------------------------------------------------------
export const clients: Client[] = [
  {
    id: 'acme-shop',
    name: 'Acme Shop',
    company: 'Acme Retail GmbH',
    email: 'billing@acme-shop.de',
    phone: '+49 30 1234 5678',
    notes: 'Net-30 terms. Billing contact prefers email.',
    status: 'active',
    since: '2024-03-01',
    deploymentCount: 2,
  },
  {
    id: 'bluepeak',
    name: 'Bluepeak Analytics',
    company: 'Bluepeak Analytics Ltd',
    email: 'dev@bluepeak.io',
    phone: '+44 20 7946 0991',
    notes: 'High request volume. Watching requests_day quota.',
    status: 'active',
    since: '2024-08-01',
    deploymentCount: 2,
  },
  {
    id: 'helios',
    name: 'Helios Energy',
    company: 'Helios Energy AG',
    email: 'api@helios.energy',
    phone: '+49 89 5520 0140',
    notes: 'Telemetry bursts trigger 429s. Quota policy = warn.',
    status: 'active',
    since: '2025-05-01',
    deploymentCount: 2,
  },
  {
    id: 'kestrel',
    name: 'Kestrel Logistics',
    company: 'Kestrel Logistics Co',
    email: 'it@kestrel-logistics.com',
    phone: '+1 312 555 0177',
    notes: 'Payment overdue on INV-2026-0140. In grace.',
    status: 'active',
    since: '2024-11-01',
    deploymentCount: 1,
  },
  {
    id: 'mori',
    name: 'Mori Design Co',
    company: 'Mori Design Co',
    email: 'studio@mori.design',
    phone: '+81 3 6701 2200',
    notes: 'Asset CDN workload. Latest invoice paid.',
    status: 'active',
    since: '2025-02-01',
    deploymentCount: 1,
  },
  {
    id: 'northwind',
    name: 'Northwind Labs',
    company: 'Northwind Labs Inc',
    email: 'ops@northwindlabs.io',
    phone: '+1 415 555 0132',
    notes: 'Three deployments: api, batch, sandbox.',
    status: 'active',
    since: '2024-01-01',
    deploymentCount: 3,
  },
  {
    id: 'quill',
    name: 'Quill & Co',
    company: 'Quill & Co Publishing',
    email: 'hello@quillandco.com',
    phone: '+44 161 555 0146',
    notes: 'Suspended for ToS abuse. Account inactive.',
    status: 'inactive',
    since: '2023-06-01',
    deploymentCount: 1,
  },
  {
    id: 'tetra',
    name: 'Tetra Robotics',
    company: 'Tetra Robotics SAS',
    email: 'eng@tetrarobotics.com',
    phone: '+33 1 70 18 99 00',
    notes: 'Fleet + telemetry. telemetry in manual grace.',
    status: 'active',
    since: '2025-10-01',
    deploymentCount: 3,
  },
]

// --- deployments ---------------------------------------------------------
function activeState(changedAt: string): DeploymentState {
  return {
    status: 'active',
    reason: 'manual',
    message: '',
    graceUntil: null,
    changedBy: 'system',
    changedAt,
  }
}

interface DepSeed {
  id: string
  clientId: string
  clientName: string
  pkg: string
  version: string
  state: DeploymentState
  req24h: number
  quotaHealth: number
  lastSeenSec: number
  createdAt: string
  secretLast4: string
  secretRotatedDays: number
  plan: string
  price: number
}

const depSeeds: DepSeed[] = [
  {
    id: 'acme-shop',
    clientId: 'acme-shop',
    clientName: 'Acme Shop',
    pkg: 'commerce-api',
    version: 'v2.4.1',
    state: {
      status: 'suspended',
      reason: 'payment_overdue',
      message:
        'Your subscription payment for INV-2026-0139 is overdue. The API has been suspended — settle the open balance to restore service immediately.',
      graceUntil: null,
      changedBy: 'auto-suspend',
      changedAt: agoIso(3 * H),
    },
    req24h: 0,
    quotaHealth: 0.64,
    lastSeenSec: 3 * H,
    createdAt: '2024-04-10',
    secretLast4: 'b71c',
    secretRotatedDays: 88,
    plan: 'Commerce Pro',
    price: 240,
  },
  {
    id: 'acme-shop-staging',
    clientId: 'acme-shop',
    clientName: 'Acme Shop',
    pkg: 'commerce-api',
    version: 'v2.5.0-rc1',
    state: activeState('2025-01-15'),
    req24h: 1204,
    quotaHealth: 0.12,
    lastSeenSec: 2 * M,
    createdAt: '2025-01-15',
    secretLast4: '0a93',
    secretRotatedDays: 12,
    plan: 'Staging',
    price: 0,
  },
  {
    id: 'bluepeak',
    clientId: 'bluepeak',
    clientName: 'Bluepeak Analytics',
    pkg: 'metrics-api',
    version: 'v4.0.2',
    state: activeState('2024-09-02'),
    req24h: 121008,
    quotaHealth: 0.83,
    lastSeenSec: 8,
    createdAt: '2024-09-02',
    secretLast4: '4d18',
    secretRotatedDays: 23,
    plan: 'Metrics Scale',
    price: 420,
  },
  {
    id: 'bluepeak-eu',
    clientId: 'bluepeak',
    clientName: 'Bluepeak Analytics',
    pkg: 'metrics-api',
    version: 'v4.0.2',
    state: activeState('2024-11-20'),
    req24h: 96544,
    quotaHealth: 0.71,
    lastSeenSec: 5,
    createdAt: '2024-11-20',
    secretLast4: 'c2f7',
    secretRotatedDays: 23,
    plan: 'Metrics Scale',
    price: 420,
  },
  {
    id: 'helios-archive',
    clientId: 'helios',
    clientName: 'Helios Energy',
    pkg: 'archive-api',
    version: 'v1.0.4',
    state: activeState('2025-05-12'),
    req24h: 412,
    quotaHealth: 0.06,
    lastSeenSec: 4 * M,
    createdAt: '2025-05-12',
    secretLast4: '9e30',
    secretRotatedDays: 30,
    plan: 'Archive',
    price: 90,
  },
  {
    id: 'helios-meter',
    clientId: 'helios',
    clientName: 'Helios Energy',
    pkg: 'telemetry-api',
    version: 'v5.2.1',
    state: activeState('2025-05-12'),
    req24h: 1240332,
    quotaHealth: 1.24,
    lastSeenSec: 2,
    createdAt: '2025-05-12',
    secretLast4: 'a155',
    secretRotatedDays: 7,
    plan: 'Telemetry Pro',
    price: 520,
  },
  {
    id: 'kestrel-track',
    clientId: 'kestrel',
    clientName: 'Kestrel Logistics',
    pkg: 'tracking-api',
    version: 'v2.9.3',
    state: {
      status: 'grace',
      reason: 'payment_overdue',
      message:
        'Your subscription payment for INV-2026-0140 is overdue. The API will be suspended on Jun 12 at 09:00 UTC unless the balance is settled.',
      graceUntil: aheadIso(13 * H + 42 * M),
      changedBy: 'operator',
      changedAt: agoIso(14 * H),
    },
    req24h: 18772,
    quotaHealth: 0.44,
    lastSeenSec: 30,
    createdAt: '2024-11-08',
    secretLast4: '3f2a',
    secretRotatedDays: 41,
    plan: 'Tracking Pro',
    price: 180,
  },
  {
    id: 'mori-assets',
    clientId: 'mori',
    clientName: 'Mori Design Co',
    pkg: 'asset-cdn',
    version: 'v1.2.0',
    state: activeState('2025-02-18'),
    req24h: 9341,
    quotaHealth: 0.22,
    lastSeenSec: 1 * M,
    createdAt: '2025-02-18',
    secretLast4: '6bb0',
    secretRotatedDays: 54,
    plan: 'CDN Standard',
    price: 120,
  },
  {
    id: 'northwind-api',
    clientId: 'northwind',
    clientName: 'Northwind Labs',
    pkg: 'ledger-api',
    version: 'v3.1.0',
    state: activeState('2024-01-22'),
    req24h: 84210,
    quotaHealth: 0.58,
    lastSeenSec: 12,
    createdAt: '2024-01-22',
    secretLast4: 'fa01',
    secretRotatedDays: 16,
    plan: 'Ledger Pro',
    price: 310,
  },
  {
    id: 'northwind-batch',
    clientId: 'northwind',
    clientName: 'Northwind Labs',
    pkg: 'batch-runner',
    version: 'v1.8.2',
    state: activeState('2024-03-05'),
    req24h: 6902,
    quotaHealth: 0.31,
    lastSeenSec: 45,
    createdAt: '2024-03-05',
    secretLast4: '7c44',
    secretRotatedDays: 16,
    plan: 'Batch',
    price: 50,
  },
  {
    id: 'northwind-sandbox',
    clientId: 'northwind',
    clientName: 'Northwind Labs',
    pkg: 'ledger-api',
    version: 'v3.1.0',
    state: activeState('2024-06-30'),
    req24h: 2318,
    quotaHealth: 0.09,
    lastSeenSec: 1 * M,
    createdAt: '2024-06-30',
    secretLast4: 'd9e2',
    secretRotatedDays: 16,
    plan: 'Sandbox',
    price: 0,
  },
  {
    id: 'quill-cms',
    clientId: 'quill',
    clientName: 'Quill & Co',
    pkg: 'content-api',
    version: 'v3.3.0',
    state: {
      status: 'suspended',
      reason: 'abuse',
      message:
        'Service suspended for terms-of-service abuse. Contact support to discuss reinstatement.',
      graceUntil: null,
      changedBy: 'operator',
      changedAt: agoIso(2 * D),
    },
    req24h: 0,
    quotaHealth: 0.38,
    lastSeenSec: 2 * D,
    createdAt: '2023-06-15',
    secretLast4: '1f88',
    secretRotatedDays: 210,
    plan: 'Content Standard',
    price: 160,
  },
  {
    id: 'tetra-fleet',
    clientId: 'tetra',
    clientName: 'Tetra Robotics',
    pkg: 'fleet-api',
    version: 'v6.0.0',
    state: activeState('2025-10-03'),
    req24h: 54109,
    quotaHealth: 0.49,
    lastSeenSec: 10,
    createdAt: '2025-10-03',
    secretLast4: '5a2d',
    secretRotatedDays: 9,
    plan: 'Fleet Pro',
    price: 390,
  },
  {
    id: 'tetra-fleet-dev',
    clientId: 'tetra',
    clientName: 'Tetra Robotics',
    pkg: 'fleet-api',
    version: 'v6.1.0-beta',
    state: activeState('2025-10-03'),
    req24h: 870,
    quotaHealth: 0.04,
    lastSeenSec: 3 * M,
    createdAt: '2025-10-03',
    secretLast4: 'e0c1',
    secretRotatedDays: 9,
    plan: 'Dev',
    price: 0,
  },
  {
    id: 'tetra-telemetry',
    clientId: 'tetra',
    clientName: 'Tetra Robotics',
    pkg: 'telemetry-api',
    version: 'v5.2.1',
    state: {
      status: 'grace',
      reason: 'manual',
      message:
        'Manual grace window opened by operator while a billing question is resolved. Service continues until the deadline.',
      graceUntil: aheadIso(61 * H + 8 * M),
      changedBy: 'operator',
      changedAt: agoIso(27 * H),
    },
    req24h: 22450,
    quotaHealth: 0.52,
    lastSeenSec: 18,
    createdAt: '2025-10-20',
    secretLast4: '8d7e',
    secretRotatedDays: 22,
    plan: 'Telemetry Pro',
    price: 520,
  },
]

export const deployments: Deployment[] = depSeeds.map((s) => ({
  id: s.id,
  name: s.id,
  subdomain: `${s.id}.snakecloud.io`,
  baseUrl: `https://${s.id}.snakecloud.io`,
  clientId: s.clientId,
  clientName: s.clientName,
  packageName: s.pkg,
  packageVersion: s.version,
  state: s.state,
  req24h: s.req24h,
  quotaHealth: s.quotaHealth,
  createdAt: s.createdAt,
  lastSeen: agoIso(s.lastSeenSec),
  controlSecretLast4: s.secretLast4,
  secretRotatedAt: agoIso(s.secretRotatedDays * D),
  subscriptionId: `sub-${s.id}`,
}))

export const subscriptions: Subscription[] = depSeeds.map((s) => ({
  id: `sub-${s.id}`,
  deploymentId: s.id,
  plan: s.plan,
  pricePerMonth: s.price,
  status:
    s.state.reason === 'payment_overdue' && s.state.status !== 'active'
      ? 'past_due'
      : 'active',
}))

// --- invoices ------------------------------------------------------------
export const invoices: Invoice[] = [
  {
    id: 'INV-2026-0145',
    number: 'INV-2026-0145',
    clientId: 'helios',
    clientName: 'Helios Energy',
    deploymentId: 'helios-meter',
    amount: 520,
    issuedAt: '2026-05-30',
    dueAt: '2026-06-09',
    status: 'pending',
    enforcement: null,
  },
  {
    id: 'INV-2026-0144',
    number: 'INV-2026-0144',
    clientId: 'bluepeak',
    clientName: 'Bluepeak Analytics',
    deploymentId: 'bluepeak',
    amount: 240,
    issuedAt: '2026-06-04',
    dueAt: '2026-06-28',
    status: 'pending',
    enforcement: null,
  },
  {
    id: 'INV-2026-0143',
    number: 'INV-2026-0143',
    clientId: 'tetra',
    clientName: 'Tetra Robotics',
    deploymentId: 'tetra-fleet',
    amount: 390,
    issuedAt: '2026-05-31',
    dueAt: '2026-06-24',
    status: 'pending',
    enforcement: null,
  },
  {
    id: 'INV-2026-0142',
    number: 'INV-2026-0142',
    clientId: 'northwind',
    clientName: 'Northwind Labs',
    deploymentId: 'northwind-api',
    amount: 310,
    issuedAt: '2026-05-22',
    dueAt: '2026-06-05',
    status: 'paid',
    enforcement: null,
  },
  {
    id: 'INV-2026-0141',
    number: 'INV-2026-0141',
    clientId: 'mori',
    clientName: 'Mori Design Co',
    deploymentId: 'mori-assets',
    amount: 120,
    issuedAt: '2026-05-27',
    dueAt: '2026-06-10',
    status: 'paid',
    enforcement: null,
  },
  {
    id: 'INV-2026-0140',
    number: 'INV-2026-0140',
    clientId: 'kestrel',
    clientName: 'Kestrel Logistics',
    deploymentId: 'kestrel-track',
    amount: 180,
    issuedAt: '2026-05-25',
    dueAt: '2026-06-08',
    status: 'overdue',
    enforcement: 'grace',
  },
  {
    id: 'INV-2026-0139',
    number: 'INV-2026-0139',
    clientId: 'acme-shop',
    clientName: 'Acme Shop',
    deploymentId: 'acme-shop',
    amount: 290,
    issuedAt: '2026-05-19',
    dueAt: '2026-06-02',
    status: 'overdue',
    enforcement: 'suspended',
  },
  {
    id: 'INV-2026-0138',
    number: 'INV-2026-0138',
    clientId: 'helios',
    clientName: 'Helios Energy',
    deploymentId: 'helios-meter',
    amount: 520,
    issuedAt: '2026-04-30',
    dueAt: '2026-05-30',
    status: 'paid',
    enforcement: null,
  },
]

// --- quotas (47 total; the 10 canonical rows + deterministic filler) ------
const canonicalQuotas: Omit<Quota, 'id'>[] = [
  { deploymentId: 'helios-meter', metric: 'requests_day', current: 1240332, limit: 1000000, onExceed: 'warn', exceededSince: agoIso(3 * D) },
  { deploymentId: 'helios-meter', metric: 'disk_db_mb', current: 7420, limit: 10240, onExceed: 'suspend', exceededSince: null },
  { deploymentId: 'bluepeak', metric: 'requests_day', current: 996210, limit: 1200000, onExceed: 'warn', exceededSince: null },
  { deploymentId: 'bluepeak', metric: 'disk_db_mb', current: 2210, limit: 8192, onExceed: 'none', exceededSince: null },
  { deploymentId: 'bluepeak-eu', metric: 'requests_day', current: 871400, limit: 1200000, onExceed: 'warn', exceededSince: null },
  { deploymentId: 'kestrel-track', metric: 'requests_day', current: 201330, limit: 500000, onExceed: 'suspend', exceededSince: null },
  { deploymentId: 'kestrel-track', metric: 'users_total', current: 322, limit: null, onExceed: 'none', exceededSince: null },
  { deploymentId: 'northwind-api', metric: 'requests_day', current: 612008, limit: 800000, onExceed: 'warn', exceededSince: null },
  { deploymentId: 'tetra-fleet', metric: 'disk_storage_mb', current: 44820, limit: 51200, onExceed: 'warn', exceededSince: null },
  { deploymentId: 'mori-assets', metric: 'disk_storage_mb', current: 9120, limit: 25600, onExceed: 'none', exceededSince: null },
]

// Deterministic filler so the table totals 47 rows (and exactly one extra >=80%).
const ALL_METRICS: Quota['metric'][] = [
  'requests_day',
  'disk_db_mb',
  'disk_storage_mb',
  'users_total',
]
function fillerQuotas(): Omit<Quota, 'id'>[] {
  const out: Omit<Quota, 'id'>[] = []
  const have = new Set(canonicalQuotas.map((q) => `${q.deploymentId}:${q.metric}`))
  let seed = 7
  const rand = () => {
    seed = (seed * 16807) % 2147483647
    return seed / 2147483647
  }
  for (const dep of deployments) {
    for (const metric of ALL_METRICS) {
      if (out.length + canonicalQuotas.length >= 47) break
      const key = `${dep.id}:${metric}`
      if (have.has(key)) continue
      const unlimited = metric === 'users_total' && rand() > 0.5
      const limit = unlimited
        ? null
        : metric === 'requests_day'
          ? 500000 + Math.round(rand() * 700000)
          : metric === 'users_total'
            ? 500 + Math.round(rand() * 4000)
            : 4096 + Math.round(rand() * 60000)
      // Keep filler utilisation below 80% so summary breach counts stay exact,
      // except a single seeded row pushed to ~91% to satisfy "3 >= 80%".
      const frac = 0.05 + rand() * 0.6
      const current =
        limit == null
          ? Math.round(rand() * 800)
          : Math.round(limit * frac)
      out.push({
        deploymentId: dep.id,
        metric,
        current,
        limit,
        onExceed: rand() > 0.6 ? 'warn' : 'none',
        exceededSince: null,
      })
    }
  }
  // Force exactly one filler row to >=80% (the off-page third breach).
  const bump = out.find((q) => q.limit != null && q.metric === 'requests_day')
  if (bump && bump.limit != null) bump.current = Math.round(bump.limit * 0.91)
  return out
}

export const quotas: Quota[] = [...canonicalQuotas, ...fillerQuotas()].map(
  (q, i) => ({ ...q, id: `q-${i}` }),
)

// --- activity feed -------------------------------------------------------
export const activity: ActivityEvent[] = [
  {
    id: 'a1',
    kind: 'suspend',
    at: agoIso(3 * H),
    parts: [
      { text: 'acme-shop', mono: true, link: { kind: 'deployment', id: 'acme-shop' } },
      { text: ' suspended — ' },
      { text: 'payment_overdue', mono: true },
      { text: ' · by auto-suspend (' },
      { text: 'INV-2026-0139', mono: true, link: { kind: 'invoice', id: 'INV-2026-0139' } },
      { text: ')' },
    ],
  },
  {
    id: 'a2',
    kind: 'grace',
    at: agoIso(14 * H),
    parts: [
      { text: 'kestrel-track', mono: true, link: { kind: 'deployment', id: 'kestrel-track' } },
      { text: ' moved to grace period — ' },
      { text: 'payment_overdue', mono: true },
      { text: ' · by operator' },
    ],
  },
  {
    id: 'a3',
    kind: 'paid',
    at: agoIso(1 * D),
    parts: [
      { text: 'INV-2026-0141', mono: true, link: { kind: 'invoice', id: 'INV-2026-0141' } },
      { text: ' marked paid — Mori Design Co · ' },
      { text: '$120.00', mono: true, strong: true },
    ],
  },
  {
    id: 'a4',
    kind: 'grace',
    at: agoIso(1 * D + 2 * H),
    parts: [
      { text: 'tetra-telemetry', mono: true, link: { kind: 'deployment', id: 'tetra-telemetry' } },
      { text: ' moved to grace period — ' },
      { text: 'manual', mono: true },
      { text: ' · by operator' },
    ],
  },
  {
    id: 'a5',
    kind: 'suspend',
    at: agoIso(2 * D),
    parts: [
      { text: 'quill-cms', mono: true, link: { kind: 'deployment', id: 'quill-cms' } },
      { text: ' suspended — ' },
      { text: 'abuse', mono: true },
      { text: ' · by operator' },
    ],
  },
  {
    id: 'a6',
    kind: 'quota',
    at: agoIso(3 * D),
    parts: [
      { text: 'helios-meter', mono: true, link: { kind: 'deployment', id: 'helios-meter' } },
      { text: ' exceeded quota ' },
      { text: 'requests_day', mono: true },
      { text: ' · policy warn — notification sent' },
    ],
  },
]

// --- dashboard attention list -------------------------------------------
export const attention: AttentionItem[] = [
  {
    id: 'att1',
    severity: 'QUOTA OVER',
    tone: 'red',
    entity: 'helios-meter',
    description:
      'requests_day at 1,240,332 / 1,000,000 (124%) — exceeded 3d ago',
    deepLinkLabel: 'Review quota →',
    to: '/quotas?deployment=helios-meter',
  },
  {
    id: 'att2',
    severity: 'GRACE ENDS',
    tone: 'amber',
    entity: 'kestrel-track',
    description:
      'suspends in 13h 42m — deadline Jun 12 03:44 UTC · reason payment_overdue',
    deepLinkLabel: 'Open deployment →',
    to: '/deployments/kestrel-track',
  },
  {
    id: 'att3',
    severity: 'OVERDUE',
    tone: 'red',
    entity: 'INV-2026-0139',
    description:
      'Acme Shop · $290.00 · 9 days overdue — linked deployment already suspended',
    deepLinkLabel: 'Open invoice →',
    to: '/invoices?focus=INV-2026-0139',
  },
  {
    id: 'att4',
    severity: 'GRACE',
    tone: 'amber',
    entity: 'tetra-telemetry',
    description:
      'suspends in 61h 08m — deadline Jun 14 03:10 UTC · reason manual',
    deepLinkLabel: 'Open deployment →',
    to: '/deployments/tetra-telemetry',
  },
]

// --- dashboard KPIs ------------------------------------------------------
export const dashboardKpis: DashboardKpis = {
  activeCount: 11,
  totalCount: 15,
  graceCount: 2,
  nextDeadlineMs: 13 * HOUR + 42 * MIN,
  suspendedCount: 2,
  suspendedBreakdown: '1 payment · 1 abuse',
  requests24h: '1.66M',
  requests24hDelta: '▲ 12% vs prior 24h',
  errorRate: '1.8%',
  errorRateDelta: '▲ 0.6 pts · 429 burst',
  mrr: '$3,420',
  mrrDelta: '▲ $180 this month',
  updatedAt: DEMO_NOW_ISO,
}

// --- usage summaries -----------------------------------------------------
const kestrelEndpoints: EndpointStat[] = [
  { route: '/v1/track/{shipment}', method: 'GET', requests: 64209, errorPct: 0.8, p95Ms: 412 },
  { route: '/v1/shipments', method: 'POST', requests: 22118, errorPct: 1.2, p95Ms: 488 },
  { route: '/v1/shipments/{id}', method: 'GET', requests: 19540, errorPct: 0.6, p95Ms: 301 },
  { route: '/v1/routes/optimize', method: 'POST', requests: 12002, errorPct: 4.1, p95Ms: 1204, anomalous: true },
  { route: '/v1/webhooks/delivery', method: 'POST', requests: 9310, errorPct: 2.4, p95Ms: 644 },
  { route: '/v1/auth/token', method: 'POST', requests: 6221, errorPct: 0.2, p95Ms: 188 },
]

const kestrelUsage: UsageSummary = {
  deploymentId: 'kestrel-track',
  requests7d: 138400,
  errorPct: 1.7,
  avgLatencyMs: 129,
  p95Ms: 472,
  uniqueUsers: 1209,
  bytesUsed: Math.round(2.1 * 1024 ** 3),
  bytesQuota: Math.round(7.8 * 1024 ** 3),
  topEndpoints: kestrelEndpoints,
}

export function getUsageSummary(deploymentId: string): UsageSummary {
  if (deploymentId === 'kestrel-track') return kestrelUsage
  const dep = deploymentsById[deploymentId]
  const base = dep ? Math.max(dep.req24h, 100) : 1000
  const r7 = base * 7
  // Generic endpoints scaled to the deployment's traffic.
  const eps: EndpointStat[] = [
    { route: '/v1/resources', method: 'GET', requests: Math.round(r7 * 0.42), errorPct: 0.7, p95Ms: 320 },
    { route: '/v1/resources/{id}', method: 'GET', requests: Math.round(r7 * 0.26), errorPct: 0.5, p95Ms: 280 },
    { route: '/v1/resources', method: 'POST', requests: Math.round(r7 * 0.15), errorPct: 1.4, p95Ms: 520 },
    { route: '/v1/auth/token', method: 'POST', requests: Math.round(r7 * 0.1), errorPct: 0.3, p95Ms: 190 },
    { route: '/v1/webhooks', method: 'POST', requests: Math.round(r7 * 0.07), errorPct: 2.1, p95Ms: 610 },
  ]
  return {
    deploymentId,
    requests7d: r7,
    errorPct: 1.5,
    avgLatencyMs: 132,
    p95Ms: 468,
    uniqueUsers: Math.max(20, Math.round(base / 80)),
    bytesUsed: Math.round(1.4 * 1024 ** 3),
    bytesQuota: Math.round(8 * 1024 ** 3),
    topEndpoints: eps,
  }
}

// --- request logs (12 canonical rows; live tail prepends more) -----------
export const requestLogs: RequestLog[] = [
  {
    id: 'log-0',
    occurredAt: '2026-06-11T14:02:31.244Z',
    deploymentId: 'kestrel-track',
    method: 'GET',
    route: '/v1/track/SHP-88412',
    status: 200,
    durationMs: 124,
    userId: 'usr_8841',
    tokenId: 'tok_live_7a02',
    ip: '84.112.55.10',
    requestBytes: 420,
    responseBytes: 1228,
  },
  {
    id: 'log-1',
    occurredAt: '2026-06-11T14:02:31.102Z',
    deploymentId: 'helios-meter',
    method: 'POST',
    route: '/v1/telemetry/batch',
    status: 429,
    durationMs: 8,
    userId: 'usr_502',
    tokenId: 'tok_live_9c44',
    ip: '52.28.114.9',
    requestBytes: 18842,
    responseBytes: 312,
    responseBody:
      '{\n  "error": "quota_exceeded",\n  "metric": "requests_day",\n  "retry_after": 34520\n}',
    context: {
      text: 'Rejected by quota requests_day (policy: warn). View quota →',
      to: '/quotas?deployment=helios-meter',
    },
  },
  {
    id: 'log-2',
    occurredAt: '2026-06-11T14:02:30.961Z',
    deploymentId: 'bluepeak',
    method: 'GET',
    route: '/v1/metrics/query?window=1h',
    status: 200,
    durationMs: 388,
    userId: 'usr_2210',
    tokenId: 'tok_live_3b71',
    ip: '13.40.92.211',
    requestBytes: 512,
    responseBytes: 10035,
  },
  {
    id: 'log-3',
    occurredAt: '2026-06-11T14:02:30.711Z',
    deploymentId: 'acme-shop-staging',
    method: 'POST',
    route: '/v1/orders',
    status: 201,
    durationMs: 242,
    userId: 'usr_1108',
    tokenId: 'tok_live_5d20',
    ip: '91.10.220.7',
    requestBytes: 2456,
    responseBytes: 980,
  },
  {
    id: 'log-4',
    occurredAt: '2026-06-11T14:02:30.580Z',
    deploymentId: 'tetra-fleet',
    method: 'GET',
    route: '/v1/fleet/status',
    status: 200,
    durationMs: 96,
    userId: 'usr_3306',
    tokenId: 'tok_live_aa19',
    ip: '193.50.7.45',
    requestBytes: 210,
    responseBytes: 3174,
  },
  {
    id: 'log-5',
    occurredAt: '2026-06-11T14:02:30.122Z',
    deploymentId: 'kestrel-track',
    method: 'POST',
    route: '/v1/routes/optimize',
    status: 500,
    durationMs: 1840,
    userId: 'usr_2188',
    tokenId: 'tok_live_7a02',
    ip: '84.112.55.10',
    requestBytes: 6320,
    responseBytes: 642,
    responseBody:
      '{\n  "error": "internal_error",\n  "request_id": "req_4f19c2"\n}',
  },
  {
    id: 'log-6',
    occurredAt: '2026-06-11T14:02:29.870Z',
    deploymentId: 'mori-assets',
    method: 'GET',
    route: '/v1/assets/a8f3.png',
    status: 304,
    durationMs: 12,
    userId: 'usr_410',
    tokenId: 'tok_live_c0d1',
    ip: '126.55.3.90',
    requestBytes: 180,
    responseBytes: 307,
  },
  {
    id: 'log-7',
    occurredAt: '2026-06-11T14:02:29.604Z',
    deploymentId: 'northwind-api',
    method: 'POST',
    route: '/v1/auth/token',
    status: 401,
    durationMs: 88,
    userId: null,
    tokenId: '—',
    ip: '20.81.140.3',
    requestBytes: 340,
    responseBytes: 614,
    responseBody: '{\n  "error": "invalid_credentials"\n}',
  },
  {
    id: 'log-8',
    occurredAt: '2026-06-11T14:02:29.377Z',
    deploymentId: 'northwind-api',
    method: 'GET',
    route: '/v1/ledger/entries?from=2026-06-01',
    status: 200,
    durationMs: 154,
    userId: 'usr_5521',
    tokenId: 'tok_live_e731',
    ip: '20.81.140.3',
    requestBytes: 280,
    responseBytes: 14942,
  },
  {
    id: 'log-9',
    occurredAt: '2026-06-11T14:02:29.020Z',
    deploymentId: 'helios-meter',
    method: 'POST',
    route: '/v1/telemetry/batch',
    status: 429,
    durationMs: 7,
    userId: 'usr_502',
    tokenId: 'tok_live_9c44',
    ip: '52.28.114.9',
    requestBytes: 18534,
    responseBytes: 312,
    responseBody:
      '{\n  "error": "quota_exceeded",\n  "metric": "requests_day",\n  "retry_after": 34521\n}',
    context: {
      text: 'Rejected by quota requests_day (policy: warn). View quota →',
      to: '/quotas?deployment=helios-meter',
    },
  },
  {
    id: 'log-10',
    occurredAt: '2026-06-11T14:02:28.812Z',
    deploymentId: 'kestrel-track',
    method: 'GET',
    route: '/v1/track/SHP-88409',
    status: 200,
    durationMs: 132,
    userId: 'usr_8841',
    tokenId: 'tok_live_7a02',
    ip: '84.112.55.10',
    requestBytes: 420,
    responseBytes: 1228,
  },
  {
    id: 'log-11',
    occurredAt: '2026-06-11T14:02:28.444Z',
    deploymentId: 'bluepeak',
    method: 'GET',
    route: '/v1/metrics/query?window=24h',
    status: 200,
    durationMs: 401,
    userId: 'usr_2231',
    tokenId: 'tok_live_3b71',
    ip: '13.40.92.211',
    requestBytes: 540,
    responseBytes: 10444,
  },
]

// --- lookups -------------------------------------------------------------
export const deploymentsById: Record<string, Deployment> = Object.fromEntries(
  deployments.map((d) => [d.id, d]),
)
export const clientsById: Record<string, Client> = Object.fromEntries(
  clients.map((c) => [c.id, c]),
)
export const invoicesById: Record<string, Invoice> = Object.fromEntries(
  invoices.map((i) => [i.id, i]),
)
export const subscriptionsById: Record<string, Subscription> =
  Object.fromEntries(subscriptions.map((s) => [s.id, s]))

export const getDeployment = (id: string) => deploymentsById[id]
export const getClient = (id: string) => clientsById[id]
export const getInvoice = (id: string) => invoicesById[id]
export const getSubscription = (id: string) => subscriptionsById[id]

export const deploymentsByClient = (clientId: string) =>
  deployments.filter((d) => d.clientId === clientId)
export const invoicesByClient = (clientId: string) =>
  invoices.filter((i) => i.clientId === clientId)
export const invoiceForDeployment = (deploymentId: string) =>
  invoices.find((i) => i.deploymentId === deploymentId)
export const quotasForDeployment = (deploymentId: string) =>
  quotas.filter((q) => q.deploymentId === deploymentId)
export const overdueInvoiceForClient = (clientId: string) =>
  invoices.find((i) => i.clientId === clientId && i.status === 'overdue')
