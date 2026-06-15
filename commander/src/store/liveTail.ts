import type { Deployment, HttpMethod, LogEntry } from './types'
import { nextId } from './transitions'

const METHODS: HttpMethod[] = ['GET', 'POST']

function pick<T>(arr: T[]): T {
  return arr[Math.floor(Math.random() * arr.length)]
}

function routesFor(d: Deployment): string[] {
  const noun = d.package.replace(/-api$/, '').replace(/-/g, '/')
  return [
    `/v1/${noun}`,
    `/v1/${noun}/${Math.floor(1000 + Math.random() * 9000)}`,
    `/v1/${noun}/query?window=1h`,
    `/v1/auth/token`,
    `/v1/webhooks/notify`,
  ]
}

// Synthesizes a plausible request-log line for the live tail. Over-quota deployments
// (helios-meter) emit 429s; others mostly 2xx with occasional errors.
export function randomLogEntry(deployments: Deployment[]): LogEntry {
  const serving = deployments.filter((d) => d.state.status !== 'suspended')
  const d = pick(serving.length ? serving : deployments)
  const method = pick(METHODS)
  const route = pick(routesFor(d))
  const over = d.quota_health > 1

  const roll = Math.random()
  let status = 200
  if (over && roll < 0.35) status = 429
  else if (roll < 0.72) status = 200
  else if (roll < 0.8) status = 201
  else if (roll < 0.85) status = 304
  else if (roll < 0.92) status = 401
  else if (roll < 0.97) status = 404
  else status = 500

  const duration =
    status === 429 ? 6 + Math.floor(Math.random() * 6) : status >= 500 ? 800 + Math.floor(Math.random() * 1400) : 40 + Math.floor(Math.random() * 420)

  const now = new Date()
  const responseBody =
    status === 429
      ? { error: 'quota_exceeded', metric: 'requests_day', retry_after: 30000 + Math.floor(Math.random() * 9000) }
      : status >= 500
        ? { error: 'internal_error' }
        : status === 401
          ? { error: 'unauthenticated' }
          : undefined

  return {
    id: nextId('log'),
    occurred_at: now.toISOString(),
    deployment_id: d.id,
    method,
    route,
    status,
    duration_ms: duration,
    user_id: status === 401 ? null : `usr_${Math.floor(1000 + Math.random() * 9000)}`,
    token_id: `tok_live_${Math.random().toString(16).slice(2, 6)}`,
    ip: `${50 + Math.floor(Math.random() * 60)}.${Math.floor(Math.random() * 255)}.${Math.floor(Math.random() * 255)}.${Math.floor(Math.random() * 255)}`,
    request_bytes: Math.floor(Math.random() * 18000),
    response_bytes: Math.floor(200 + Math.random() * 12000),
    response_body: responseBody,
    context: status === 429 ? 'Rejected by quota requests_day (policy: warn)' : undefined,
  }
}
