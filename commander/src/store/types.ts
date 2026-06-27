// Domain model. Mirrors the commander_* tables (commander_packages, commander_quotas,
// commander_usage_rollups, commander_request_logs) of the centralized app. The Commander reads
// them in-process (same app + DB), so this is the live shape, not a mirror.

export type PackageStatus = 'active' | 'grace' | 'suspended'

export type PaymentTerms = 'prepaid' | 'net_15' | 'net_30' | 'net_60'

export type StateReason = 'manual' | 'payment_overdue' | 'abuse' | string

export type ClientStatus = 'active' | 'inactive'

export interface Client {
  id: number
  slug: string // handle legible (antes era la PK)
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
  id: number
  slug?: string // handle legible (antes era la PK)
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

// Tabla unica commander_packages: una fila por package de negocio (en disco). Mezcla CATALOGO
// (label/description/listing_status, siempre presente, sincronizado desde disco) con lo OPERATIVO de
// la instancia viva 1:1 (slug/database/cliente/estado runtime/KPIs), que es null hasta provisionar.
// `provisioned_at != null` marca la instancia viva. El de-provision (soft) anula lo operativo y conserva
// el catalogo + el historial de billing. La API la enriquece con on_disk + plan_count + relaciones.
export interface Package {
  id: number
  name: string // StudlyCase package name (Shop, Blog…) — clave de negocio
  // ── Catalogo (registro) ──
  label: string | null
  description: string | null
  listing_status: 'active' | 'inactive' // visible en catalogo (distinto del estado runtime)
  on_disk?: boolean
  plan_count?: number
  // ── Operativo (instancia viva) — null hasta provisionar ──
  provisioned_at: string | null // ISO; null == solo catalogo (no provisionado)
  slug: string | null // snake/kebab slug del PATH (segmento /api/v1/<slug>), parte de UNIQUE(client_id, slug)
  database: string | null // BD propia del package
  client_id: number | null
  subscription_id: number | null
  status: PackageStatus // estado runtime (active|grace|suspended); relevante cuando esta provisionado
  reason: StateReason | null
  message: string | null
  grace_until: string | null // ISO, or null
  changed_at: string | null // ISO; timestamp de la última transición del state machine
  requests_24h: number
  quota_health: number // 0..>1 worst-metric ratio
  last_seen: string | null // ISO
  created_at: string // ISO
  updated_at: string // ISO
  // ── Relaciones (cuando esta provisionado y la API las carga) ──
  client?: Client | null
  subscription?: Subscription | null
  quotas?: QuotaMetric[]
}

export type MetricKey = 'requests_day' | 'disk_db_mb' | 'disk_storage_mb' | 'users_total'

export type OnExceed = 'none' | 'warn' | 'suspend'

export interface QuotaMetric {
  id: number
  package_id: number
  metric_key: MetricKey
  current_value: number
  limit_value: number | null // null == unlimited
  on_exceed: OnExceed
  exceeded_at: string | null // ISO when first over, else null
  unit: 'count' | 'mb'
}

export type BillingStatus = 'paid' | 'pending' | 'overdue'

export interface Invoice {
  id: number
  number: string // e.g. "INV-2026-0140" (handle de negocio legible)
  client_id: number
  package_id: number
  amount: number
  issued_at: string // ISO
  due_at: string // ISO
  status: BillingStatus
  /** Enforcement applied to the linked package for this overdue invoice. */
  enforcement: 'grace' | 'suspended' | null
}

// Un término de subscripción (1:N por package = historial de planes). El VIGENTE es el de
// `ended_at == null` (al que apunta `Package.subscription_id`); los cerrados son histórico. `plan`/
// `price_monthly` son snapshots congelados; `plan_id` es trazabilidad al catálogo.
export interface Subscription {
  id: number
  client_id: number
  package_id: number
  plan_id: number | null // FK al catálogo (snapshot manda en billing)
  plan: string // snapshot del nombre, e.g. "Tracking Pro"
  price_monthly: number // snapshot del precio
  billing_status: BillingStatus
  auto_suspend: boolean
  started_at: string // ISO
  ended_at: string | null // null = término vigente; ISO = histórico
}

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

// One row of commander_request_logs (written by CoreMeteringMiddleware). The API enriches each row by
// JOINing commander_packages (package name + path slug) and commander_clients (client slug = host).
// `package`/`slug`/`subdomain` are enrichment fields from that join, not stored columns.
export interface LogEntry {
  id: string
  package_id: number
  created_at: string // ISO
  package?: string // enriched: package.name (StudlyCase)
  slug?: string // enriched: package.slug (path segment)
  subdomain?: string // enriched: client.slug (host)
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

export type ActivityKind = 'suspend' | 'grace' | 'resume' | 'paid' | 'quota' | 'change_plan'

export interface ActivityEvent {
  id: number
  created_at: string // ISO
  kind: ActivityKind
  package_id?: number
  invoice_id?: number
  reason?: StateReason | null // origen del evento se infiere de aquí (no hay actor guardado)
  message?: string
}

export interface TopEndpoint {
  route: string
  method: HttpMethod
  requests: number
  error_rate: number // 0..1
  p95_ms: number
}

// One aggregated row of the package's core_usage_rollups (served by GET /packages/{name}/usage).
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

// One row of the platform usage response (GET /usage/platform) — per package per bucket, aggregated
// on the fly from commander_usage_rollups (no cache table). Powers the Dashboard/Usage stacked +
// error-rate charts.
export interface UsageSnapshot {
  package_id: number
  period_start: string // ISO hour bucket
  request_count: number
  error_count: number
  count_4xx: number // requests in the 4xx class (for the error-rate breakdown)
  count_5xx: number // requests in the 5xx class
}

export interface OperatorSettings {
  default_grace_hours: number
  operator_name: string
  operator_email: string
  notify_on_suspend: boolean
  notify_on_overdue: boolean
}

// Contexto que PackageDetail pasa a sus tabs vía <Outlet context>. `pkg` es la fila unificada del
// package; `packageRow` es la MISMA fila solo si está provisionada (su instancia viva), o undefined.
export interface PackageContext {
  pkg: Package
  packageRow: Package | undefined
  plans: Plan[]
}

// ── Git insights (commander_package_repos / _commits / _activity_daily) ──────────
// Powers the Insights tab: contribution heatmap + code-frequency + recent commits. Fed by the
// `commander:git:sync` command; served by GET /packages/{name}/insights as one envelope.
export interface GitRepoInfo {
  provider: string
  workspace: string | null
  repo_slug: string | null
  path_prefix: string | null // monorepo path scope, e.g. src/packages/Acme/Shop
  default_branch: string | null
  last_synced_at: string | null // ISO; null == never synced
  last_synced_sha: string | null
}

// One row of commander_package_activity_daily (daily git-activity rollup).
export interface GitDailyActivity {
  day: string // YYYY-MM-DD (UTC)
  commit_count: number
  additions: number
  deletions: number
  files_changed: number
}

// One row of commander_package_commits (path-scoped line counts).
export interface GitCommit {
  sha: string
  committed_at: string | null // ISO
  author_name: string | null
  author_email: string | null
  message: string | null
  additions: number
  deletions: number
  files_changed: number
}

// All-time aggregate (over the whole history, not the calendar window).
export interface GitInsightsSummary {
  total_commits: number
  total_additions: number
  total_deletions: number
  total_authors: number
  active_days: number // days with ≥1 commit inside the requested window
  first_commit_at: string | null // ISO
  last_commit_at: string | null // ISO
}

export interface PackageInsights {
  repo: GitRepoInfo | null
  summary: GitInsightsSummary
  daily: GitDailyActivity[] // bounded to the requested window
  recent_commits: GitCommit[]
}

// A recent commit across the whole fleet (GET /commits) — a GitCommit enriched with its package
// (name + catalog label) for the Dashboard "Recent commits" card.
export interface RecentCommit extends GitCommit {
  package_id: number
  package: string | null // package name (business key, for the /packages link)
  package_label: string | null
}
