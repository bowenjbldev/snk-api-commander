// Domain model. Mirrors the commander_* tables (commander_packages, commander_quota_metrics,
// commander_usage_rollups, commander_request_logs) of the centralized deploy. The Commander reads
// them in-process (same app + DB), so this is the live shape, not a mirror.

export type DeploymentStatus = 'active' | 'grace' | 'suspended'

export type PaymentTerms = 'prepaid' | 'net_15' | 'net_30' | 'net_60'

export type StateReason = 'manual' | 'payment_overdue' | 'abuse' | string

export type ChangedBy = 'operator' | 'cli' | 'quota' | 'cms' | 'auto-suspend'

export interface DeploymentState {
  status: DeploymentStatus
  reason: StateReason | null
  message: string | null
  grace_until: string | null // ISO, or null
  changed_by: ChangedBy
  changed_at: string // ISO
}

export type ClientStatus = 'active' | 'inactive'

export interface Client {
  id: string
  name: string
  company?: string // nullable en backend (commander_clients.company)
  email: string
  phone?: string
  notes?: string
  status: ClientStatus
  created_at: string // ISO
  // Billing (all optional; captured in the client-first provisioning flow)
  billing_email?: string | null
  tax_id?: string | null
  address_line1?: string | null
  address_line2?: string | null
  city?: string | null
  postal_code?: string | null
  country?: string | null // ISO-3166 alpha-2
  payment_terms?: PaymentTerms | null
}

// A catalog offering: a sellable plan tied to a business package, with a price and the default
// quota limits applied to the deploy when provisioned.
export interface Plan {
  id: string
  package: string // src/packages business package (Shop, Blog…)
  name: string // plan label (Basic, Pro…)
  description?: string | null
  price_monthly: number
  q_requests_day?: number | null
  q_disk_db_mb?: number | null
  q_disk_storage_mb?: number | null
  q_users_total?: number | null
  status: 'active' | 'inactive'
}

export interface Deployment {
  id: string // slug == name
  name: string
  subdomain: string
  client_id: string
  package: string // e.g. "tracking-api"
  version: string // e.g. "v2.9.3"
  state: DeploymentState
  requests_24h: number
  quota_health: number // 0..>1 worst-metric ratio
  last_seen: string // ISO
  base_url: string
  subscription_id: string | null
  created_at: string // ISO
}

export type MetricKey = 'requests_day' | 'disk_db_mb' | 'disk_storage_mb' | 'users_total'

export type OnExceed = 'none' | 'warn' | 'suspend'

export interface QuotaMetric {
  id: string
  deployment_id: string
  metric_key: MetricKey
  current_value: number
  limit_value: number | null // null == unlimited
  on_exceed: OnExceed
  exceeded_at: string | null // ISO when first over, else null
  unit: 'count' | 'mb'
}

export type BillingStatus = 'paid' | 'pending' | 'overdue'

export interface Invoice {
  id: string // e.g. "INV-2026-0140"
  client_id: string
  deployment_id: string
  amount: number
  issued_at: string // ISO
  due_at: string // ISO
  status: BillingStatus
  /** Enforcement applied to the linked deployment for this overdue invoice. */
  enforcement: 'grace' | 'suspended' | null
}

export interface Subscription {
  id: string
  client_id: string
  deployment_id: string
  plan: string // e.g. "Tracking Pro"
  price_monthly: number
  billing_status: BillingStatus
  auto_suspend: boolean
  started_at: string // ISO
}

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

// One row of commander_request_logs (written by CoreMeteringMiddleware), tagged with deployment_id
// by the API (joined via package_id). Shape matches the real table columns.
export interface LogEntry {
  id: string
  occurred_at: string // ISO
  deployment_id: string
  method: HttpMethod
  route: string
  path?: string
  status: number
  duration_ms: number
  user_id: string | null
  token_id: string
  ip: string
  request_bytes: number
  response_bytes: number
  memory_kb?: number
}

export type ActivityKind = 'suspend' | 'grace' | 'resume' | 'paid' | 'quota'

export interface ActivityEvent {
  id: string
  at: string // ISO
  kind: ActivityKind
  deployment_id?: string
  invoice_id?: string
  reason?: StateReason | null
  by?: ChangedBy
  message?: string
}

export interface TopEndpoint {
  route: string
  method: HttpMethod
  requests: number
  error_rate: number // 0..1
  p95_ms: number
}

// One aggregated row of the deploy's core_usage_rollups (served by GET /deployments/{id}/usage).
export interface UsageRollup {
  period: 'hour' | 'day'
  period_start: string // ISO bucket start
  route: string
  method: HttpMethod
  status_class: '2xx' | '3xx' | '4xx' | '5xx'
  request_count: number
  error_count: number
  avg_duration_ms: number
  p95_duration_ms: number
  total_request_bytes: number
  total_response_bytes: number
  unique_users: number
}

// One row of the Commander local usage cache (commander_usage_snapshots) — per deployment per hour.
// Served by GET /usage/platform; powers the Dashboard/Usage stacked + error-rate charts.
export interface UsageSnapshot {
  deployment_id: string
  period_start: string // ISO hour bucket
  request_count: number
  error_count: number
}

export interface OperatorSettings {
  default_grace_hours: number
  operator_name: string
  operator_email: string
  notify_on_suspend: boolean
  notify_on_overdue: boolean
}

// Registered business package (type) — la entidad central del panel package-céntrico. Se sincroniza
// desde disco (packages/*) y guarda metadatos editables del operador; la API la enriquece con conteos.
export interface RegisteredPackage {
  name: string
  label: string | null
  description: string | null
  status: 'active' | 'inactive'
  on_disk?: boolean
  deployment_count?: number
  has_deployment?: boolean
  plan_count?: number
}

// Contexto que PackageDetail pasa a sus tabs vía <Outlet context>.
export interface PackageContext {
  pkg: RegisteredPackage
  deployment: Deployment | undefined
  plans: Plan[]
}
