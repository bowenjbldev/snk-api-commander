// Thin fetch wrapper for the Commander API. In dev the SPA calls a same-origin `/api/v1`
// path that Vite proxies to the backend (see vite.config.ts) — no CORS, no cert prompt.
// For a production build, set VITE_API_BASE to the deploy's origin (e.g. https://commander.host/api/v1).

import type { GitDailyActivity, LogEntry, OperatorSettings, Package, PackageInsights, RecentCommit, UsageRollup, UsageSnapshot } from '../store/types'

const BASE = (import.meta.env.VITE_API_BASE as string | undefined) ?? '/api/v1'
const TOKEN_KEY = 'commander_token'

export interface User {
  id: number
  name: string
  email: string
  all_permissions?: string[]
  role_names?: string[]
}

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string | null): void {
  if (token) localStorage.setItem(TOKEN_KEY, token)
  else localStorage.removeItem(TOKEN_KEY)
}

export class ApiError extends Error {
  status: number

  constructor(status: number, message: string) {
    super(message)
    this.name = 'ApiError'
    this.status = status
  }
}

// A 401 anywhere means the token is gone/expired — the auth store wires this to log out.
let onUnauthorized: (() => void) | null = null
export function setUnauthorizedHandler(fn: () => void): void {
  onUnauthorized = fn
}

interface RequestOptions {
  method?: string
  body?: unknown
}

export async function api<T = unknown>(path: string, opts: RequestOptions = {}): Promise<T> {
  const token = getToken()
  const hasBody = opts.body !== undefined

  const res = await fetch(`${BASE}${path}`, {
    method: opts.method ?? 'GET',
    headers: {
      Accept: 'application/json',
      ...(hasBody ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: hasBody ? JSON.stringify(opts.body) : undefined,
  })

  if (res.status === 401) {
    onUnauthorized?.()
    throw new ApiError(401, 'Session expired — please sign in again.')
  }

  const text = await res.text()
  let data: unknown = null
  if (text) {
    // A non-JSON body (e.g. an HTML error page from a reverse proxy on a 502) must surface as a
    // structured ApiError, not a raw SyntaxError that bypasses our error contract.
    try {
      data = JSON.parse(text)
    } catch {
      data = null
    }
  }

  if (!res.ok) {
    const body = data && typeof data === 'object' ? (data as Record<string, unknown>) : null
    const detail = body ? (body.error ?? body.detail ?? body.message) : null
    throw new ApiError(res.status, typeof detail === 'string' ? detail : `Request failed (${res.status})`)
  }

  return data as T
}

export const authApi = {
  login: (email: string, password: string) =>
    api<{ token: string; user: User }>('/auth/login', { method: 'POST', body: { email, password } }),
  me: () => api<{ user: User }>('/auth/me'),
  logout: () => api('/auth/logout', { method: 'POST' }),
}

// Platform-wide hourly usage (aggregated Commander snapshot cache) for the Dashboard/Usage charts.
export function platformUsage(
  params: { period?: 'hour' | 'day'; hours?: number } = {},
): Promise<{ data: UsageSnapshot[] }> {
  const qs = new URLSearchParams()
  if (params.period) qs.set('period', params.period)
  if (params.hours) qs.set('hours', String(params.hours))
  const q = qs.toString()
  return api<{ data: UsageSnapshot[] }>(`/usage/platform${q ? `?${q}` : ''}`)
}

// Platform-wide request logs (all packages). The API joins commander_request_logs to
// commander_packages (by package_id) and commander_clients to enrich each row with the package name,
// path slug, and client slug (host/subdomain). `package` is an OPTIONAL filter (the StudlyCase
// package name) — omit it for the cross-fleet view.
export function platformLogs(
  params: { package?: string; limit?: number; since?: string; status_class?: 2 | 3 | 4 | 5; method?: string } = {},
): Promise<{ data: LogEntry[] }> {
  const qs = new URLSearchParams()
  if (params.package) qs.set('package', params.package)
  if (params.limit) qs.set('limit', String(params.limit))
  if (params.since) qs.set('since', params.since)
  if (params.status_class) qs.set('status_class', String(params.status_class))
  if (params.method) qs.set('method', params.method)
  const q = qs.toString()
  return api<{ data: LogEntry[] }>(`/logs${q ? `?${q}` : ''}`)
}

// Platform-wide recent git commits (all packages, newest first) for the Dashboard "Recent commits"
// card. Each commit is enriched with its package name/label by the API.
export function platformCommits(params: { limit?: number } = {}): Promise<{ data: RecentCommit[] }> {
  const qs = new URLSearchParams()
  if (params.limit) qs.set('limit', String(params.limit))
  const q = qs.toString()
  return api<{ data: RecentCommit[] }>(`/commits${q ? `?${q}` : ''}`)
}

// Platform-wide git code frequency: daily additions/deletions summed across all packages over a
// window (in days, default ~12 weeks). Same GitDailyActivity shape as a package's insights().daily.
export function platformCodeFrequency(params: { days?: number } = {}): Promise<{ data: GitDailyActivity[] }> {
  const qs = new URLSearchParams()
  if (params.days) qs.set('days', String(params.days))
  const q = qs.toString()
  return api<{ data: GitDailyActivity[] }>(`/code-frequency${q ? `?${q}` : ''}`)
}

// Registro de packages de negocio (tipos): el hub package-céntrico. list() sincroniza desde disco y
// enriquece cada fila con on_disk + el package provisionado anidado + conteos de planes. Metadatos
// editables (label/description/status). Como el modelo es 1:1, las acciones operativas del package
// provisionado (estado, usage, logs, de-provision) también cuelgan de aquí, siempre por PK.
export const packagesApi = {
  list: () => api<Package[]>('/packages'),
  update: (id: number, patch: Partial<Pick<Package, 'label' | 'description' | 'listing_status'>>) =>
    api<Package>(`/packages/${id}`, { method: 'PATCH', body: patch }),

  // ── operaciones de instancia, siempre por PK (el nombre se repite entre clientes) ──
  suspend: (id: number, body: { reason: string; message?: string | null }) =>
    api<Package>(`/packages/${id}/suspend`, { method: 'POST', body }),
  grace: (id: number, body: { reason: string; message?: string | null; grace_until?: string }) =>
    api<Package>(`/packages/${id}/grace`, { method: 'POST', body }),
  resume: (id: number) => api<Package>(`/packages/${id}/resume`, { method: 'POST' }),
  changePlan: (id: number, plan_id: number) =>
    api<Package>(`/packages/${id}/change-plan`, { method: 'POST', body: { plan_id } }),
  deprovision: (id: number) => api(`/packages/${id}`, { method: 'DELETE' }),

  // A package's real usage rollups (commander_usage_rollups, queried in-process by package_id).
  usage: (
    id: number,
    params: { period?: 'hour' | 'day'; from?: string; to?: string } = {},
  ): Promise<{ data: UsageRollup[] }> => {
    const qs = new URLSearchParams()
    if (params.period) qs.set('period', params.period)
    if (params.from) qs.set('from', params.from)
    if (params.to) qs.set('to', params.to)
    const q = qs.toString()
    return api<{ data: UsageRollup[] }>(`/packages/${id}/usage${q ? `?${q}` : ''}`)
  },

  // A package's real recent request logs (commander_request_logs, queried in-process by package_id).
  // No real-time stream — fetch on demand / poll.
  logs: (
    id: number,
    params: { limit?: number; since?: string; status_class?: 2 | 3 | 4 | 5 } = {},
  ): Promise<{ data: LogEntry[] }> => {
    const qs = new URLSearchParams()
    if (params.limit) qs.set('limit', String(params.limit))
    if (params.since) qs.set('since', params.since)
    if (params.status_class) qs.set('status_class', String(params.status_class))
    const q = qs.toString()
    return api<{ data: LogEntry[] }>(`/packages/${id}/logs${q ? `?${q}` : ''}`)
  },

  // A package's git insights (commander_package_* tables, synced by `commander:git:sync`):
  // repo/sync status + all-time summary + daily activity (heatmap/code-frequency) + recent commits.
  insights: (id: number, params: { days?: number } = {}): Promise<{ data: PackageInsights }> => {
    const qs = new URLSearchParams()
    if (params.days) qs.set('days', String(params.days))
    const q = qs.toString()
    return api<{ data: PackageInsights }>(`/packages/${id}/insights${q ? `?${q}` : ''}`)
  },
}

// Operator profile + platform preferences (singleton, persisted server-side).
export const settingsApi = {
  get: () => api<OperatorSettings>('/settings'),
  update: (patch: Partial<OperatorSettings>) =>
    api<OperatorSettings>('/settings', { method: 'PUT', body: patch }),
}
