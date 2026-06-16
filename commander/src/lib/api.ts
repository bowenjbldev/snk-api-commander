// Thin fetch wrapper for the Commander API. In dev the SPA calls a same-origin `/api/v1`
// path that Vite proxies to the backend (see vite.config.ts) — no CORS, no cert prompt.
// For a production build, set VITE_API_BASE to the deploy's origin (e.g. https://commander.host/api/v1).

import type { LogEntry, UsageRollup, UsageSnapshot } from '../store/types'

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

// Pulls a deploy's real usage rollups (proxied from its signed /control/usage) for the Usage tab.
export function deploymentUsage(
  id: string,
  params: { period?: 'hour' | 'day'; from?: string; to?: string } = {},
): Promise<{ data: UsageRollup[] }> {
  const qs = new URLSearchParams()
  if (params.period) qs.set('period', params.period)
  if (params.from) qs.set('from', params.from)
  if (params.to) qs.set('to', params.to)
  const q = qs.toString()
  return api<{ data: UsageRollup[] }>(`/deployments/${id}/usage${q ? `?${q}` : ''}`)
}

// Pulls a deploy's real recent request logs (proxied from its signed /control/logs, tagged with
// deployment_id) for the Logs view. No real-time stream — fetch on demand / poll.
export function deploymentLogs(
  id: string,
  params: { limit?: number; since?: string; status_class?: 2 | 3 | 4 | 5 } = {},
): Promise<{ data: LogEntry[] }> {
  const qs = new URLSearchParams()
  if (params.limit) qs.set('limit', String(params.limit))
  if (params.since) qs.set('since', params.since)
  if (params.status_class) qs.set('status_class', String(params.status_class))
  const q = qs.toString()
  return api<{ data: LogEntry[] }>(`/deployments/${id}/logs${q ? `?${q}` : ''}`)
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
