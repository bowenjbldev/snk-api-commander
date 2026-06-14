// SnakeCloud CMS — domain model. One coherent dataset backs every screen.

// ---------------------------------------------------------------------------
// State semantics
// ---------------------------------------------------------------------------
export type DeploymentStatus = 'active' | 'grace' | 'suspended'
export type StateReason = 'payment_overdue' | 'abuse' | 'manual'

export interface DeploymentState {
  status: DeploymentStatus
  reason: StateReason
  /** Operator-authored copy returned in the HTTP 402 body. */
  message: string
  /** ISO deadline at which grace converts to suspended; null unless status==='grace'. */
  graceUntil: string | null
  changedBy: string
  /** ISO timestamp of the last transition. */
  changedAt: string
}

// ---------------------------------------------------------------------------
// Clients
// ---------------------------------------------------------------------------
export type ClientStatus = 'active' | 'inactive'

export interface Client {
  id: string
  name: string
  company: string
  email: string
  phone: string
  notes: string
  status: ClientStatus
  /** ISO date the client relationship started. */
  since: string
  /** Convenience: number of deployments owned (derived, but stored for tables). */
  deploymentCount: number
}

// ---------------------------------------------------------------------------
// Quotas
// ---------------------------------------------------------------------------
export type QuotaMetric =
  | 'requests_day'
  | 'disk_db_mb'
  | 'disk_storage_mb'
  | 'users_total'
export type QuotaPolicy = 'none' | 'warn' | 'suspend'

export interface Quota {
  id: string
  deploymentId: string
  metric: QuotaMetric
  current: number
  /** null === unlimited. */
  limit: number | null
  onExceed: QuotaPolicy
  /** ISO time the limit was first exceeded, or null. */
  exceededSince: string | null
}

// ---------------------------------------------------------------------------
// Subscriptions
// ---------------------------------------------------------------------------
export interface Subscription {
  id: string
  deploymentId: string
  plan: string
  /** Monthly price in whole USD. */
  pricePerMonth: number
  status: 'active' | 'past_due' | 'canceled'
}

// ---------------------------------------------------------------------------
// Deployments
// ---------------------------------------------------------------------------
export interface Deployment {
  /** Slug, also used as the route param and display name. */
  id: string
  name: string
  subdomain: string
  baseUrl: string
  clientId: string
  clientName: string
  packageName: string
  packageVersion: string
  state: DeploymentState
  /** Requests served in the trailing 24h. */
  req24h: number
  /** Worst quota utilisation 0..n (1 === 100%). */
  quotaHealth: number
  createdAt: string
  lastSeen: string
  controlSecretLast4: string
  secretRotatedAt: string
  subscriptionId: string
}

// ---------------------------------------------------------------------------
// Invoices
// ---------------------------------------------------------------------------
export type InvoiceStatus = 'paid' | 'pending' | 'overdue'
/** What enforcement happened on the linked deployment for an overdue invoice. */
export type InvoiceEnforcement = 'grace' | 'suspended' | null

export interface Invoice {
  id: string
  number: string
  clientId: string
  clientName: string
  deploymentId: string
  /** Amount in whole USD. */
  amount: number
  issuedAt: string
  dueAt: string
  status: InvoiceStatus
  enforcement: InvoiceEnforcement
}

// ---------------------------------------------------------------------------
// Activity feed
// ---------------------------------------------------------------------------
export type ActivityKind = 'suspend' | 'grace' | 'paid' | 'quota' | 'resume'

export interface ActivityEvent {
  id: string
  kind: ActivityKind
  at: string
  /** Pre-segmented sentence parts so entity links render as mono links. */
  parts: ActivitySegment[]
}
export interface ActivitySegment {
  text: string
  mono?: boolean
  link?: { kind: 'deployment' | 'invoice' | 'client'; id: string }
  strong?: boolean
}

// ---------------------------------------------------------------------------
// Dashboard attention list
// ---------------------------------------------------------------------------
export type AttentionSeverity =
  | 'QUOTA OVER'
  | 'GRACE ENDS'
  | 'OVERDUE'
  | 'GRACE'
export interface AttentionItem {
  id: string
  severity: AttentionSeverity
  tone: 'red' | 'amber'
  entity: string
  /** Description with optional inline mono spans. */
  description: string
  deepLinkLabel: string
  to: string
}

// ---------------------------------------------------------------------------
// Request logs
// ---------------------------------------------------------------------------
export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

export interface RequestLog {
  id: string
  /** ISO with ms precision. */
  occurredAt: string
  deploymentId: string
  method: HttpMethod
  route: string
  status: number
  durationMs: number
  userId: string | null
  tokenId: string
  ip: string
  requestBytes: number
  responseBytes: number
  /** Optional JSON response body (string), shown in the detail panel. */
  responseBody?: string
  /** Optional cause context linking to a quota etc. */
  context?: { text: string; to?: string }
}

// ---------------------------------------------------------------------------
// Usage (per deployment) — KPI strip + top endpoints
// ---------------------------------------------------------------------------
export interface EndpointStat {
  route: string
  method: HttpMethod
  requests: number
  errorPct: number
  p95Ms: number
  anomalous?: boolean
}

export interface UsageSummary {
  deploymentId: string
  requests7d: number
  errorPct: number
  avgLatencyMs: number
  p95Ms: number
  uniqueUsers: number
  bytesUsed: number
  bytesQuota: number
  topEndpoints: EndpointStat[]
}

// ---------------------------------------------------------------------------
// Dashboard KPIs
// ---------------------------------------------------------------------------
export interface DashboardKpis {
  activeCount: number
  totalCount: number
  graceCount: number
  nextDeadlineMs: number
  suspendedCount: number
  suspendedBreakdown: string
  requests24h: string
  requests24hDelta: string
  errorRate: string
  errorRateDelta: string
  mrr: string
  mrrDelta: string
  updatedAt: string
}
