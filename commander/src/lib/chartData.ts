// Deterministic chart-data generators (seeded PRNG → stable shapes across renders).
import type { HttpMethod, Package, TopEndpoint, UsageRollup, UsageSnapshot } from '../store/types'

export interface Point {
  group: string
  date: Date
  value: number
}

export const PALETTE = ['#6929c4', '#1192e8', '#005d5d', '#9f1853', '#fa4d56']
export const OTHER_COLOR = '#8d8d8d'
export const COLOR_4XX = '#d2a106'
export const COLOR_5XX = '#fa4d56'

function mulberry32(seed: number): () => number {
  let a = seed
  return () => {
    a |= 0
    a = (a + 0x6d2b79f5) | 0
    let t = Math.imul(a ^ (a >>> 15), 1 | a)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

function hourly(group: string, base: number, seed: number, points: number, opts?: { diurnal?: number; noise?: number; spikeAt?: number; spikeMul?: number }): Point[] {
  const rnd = mulberry32(seed)
  const now = Date.now()
  const diurnal = opts?.diurnal ?? 0.35
  const noise = opts?.noise ?? 0.25
  const out: Point[] = []
  for (let i = 0; i < points; i++) {
    const date = new Date(now - (points - 1 - i) * 3_600_000)
    const hour = date.getUTCHours()
    const wave = 1 + diurnal * Math.sin((hour / 24) * Math.PI * 2 - Math.PI / 2)
    let value = base * wave * (1 - noise / 2 + rnd() * noise)
    if (opts?.spikeAt != null && i === opts.spikeAt) value *= opts.spikeMul ?? 3
    out.push({ group, date, value: Math.max(0, value) })
  }
  return out
}

function seedFrom(s: string): number {
  let h = 2166136261
  for (let i = 0; i < s.length; i++) {
    h ^= s.charCodeAt(i)
    h = Math.imul(h, 16777619)
  }
  return h >>> 0
}

// Dashboard: requests/hour, last 48h, stacked by top-4 packages + "other".
export function requestsPerHourStacked(packages: Package[]): { data: Point[]; groups: string[] } {
  const sorted = [...packages].sort((a, b) => b.requests_24h - a.requests_24h)
  const top = sorted.slice(0, 4)
  const rest = sorted.slice(4)
  const groups = [...top.map((p) => p.name), 'other']
  const data: Point[] = []
  top.forEach((p, idx) => data.push(...hourly(p.name, p.requests_24h / 24, seedFrom(String(p.id)) + idx, 48)))
  const otherBase = rest.reduce((s, p) => s + p.requests_24h, 0) / 24
  data.push(...hourly('other', otherBase, 99, 48))
  return { data, groups }
}

// Dashboard: combined 4xx+5xx error rate %, last 48h, with a burst spike.
export function errorRate48(): Point[] {
  return hourly('error %', 1.8, 7, 48, { diurnal: 0.2, noise: 0.4, spikeAt: 33, spikeMul: 3.6 }).map((p) => ({
    ...p,
    value: Math.min(7, p.value),
  }))
}

// Usage tab (per package).
export function usageRequests(pkg: Package): Point[] {
  return hourly('requests', Math.max(pkg.requests_24h / 24, 20), seedFrom(String(pkg.id)), 48, { diurnal: 0.4 })
}
export function usageErrorRate(pkg: Package): Point[] {
  const s = seedFrom(String(pkg.id))
  return [
    ...hourly('4xx', 1.2, s + 1, 48, { diurnal: 0.2, noise: 0.5 }),
    ...hourly('5xx', 0.4, s + 2, 48, { diurnal: 0.15, noise: 0.6 }),
  ]
}
export function usageLatency(pkg: Package): Point[] {
  const s = seedFrom(String(pkg.id))
  return [
    ...hourly('avg', 129, s + 3, 48, { diurnal: 0.18, noise: 0.2 }),
    ...hourly('p95', 472, s + 4, 48, { diurnal: 0.22, noise: 0.25 }),
  ]
}
export function usageTransfer(pkg: Package): Point[] {
  const s = seedFrom(String(pkg.id))
  const base = Math.max(pkg.requests_24h / 24 / 8, 5)
  return [
    ...hourly('in', base, s + 5, 48, { diurnal: 0.35 }),
    ...hourly('out', base * 3.5, s + 6, 48, { diurnal: 0.35 }),
  ]
}

// ── Real usage (per deployment) — from the deploy's core_usage_rollups ───────────
// Buckets rows by period_start and emits the same group names the chart options expect,
// so chartOptions.ts is untouched. Used by the deployment Usage tab.

export interface UsageSeries {
  requests: Point[] // group 'requests'
  errorRate: Point[] // groups '4xx' | '5xx' (percent of bucket)
  latency: Point[] // groups 'avg' | 'p95' (ms)
  transfer: Point[] // groups 'in' | 'out' (MB)
  kpis: {
    totalRequests: number
    errorRate: number // 0..1
    avgLatencyMs: number
    p95Ms: number
    bytesIn: number
    bytesOut: number
    uniqueUsers: number
  }
  topEndpoints: TopEndpoint[]
}

const MB = 1024 * 1024

export function rollupsToUsageSeries(rollups: UsageRollup[]): UsageSeries {
  const buckets = new Map<string, UsageRollup[]>()
  for (const r of rollups) {
    const arr = buckets.get(r.period_start)
    if (arr) arr.push(r)
    else buckets.set(r.period_start, [r])
  }

  const requests: Point[] = []
  const errorRate: Point[] = []
  const latency: Point[] = []
  const transfer: Point[] = []

  const sum = (rows: UsageRollup[], pick: (r: UsageRollup) => number) => rows.reduce((s, r) => s + pick(r), 0)

  for (const key of [...buckets.keys()].sort()) {
    const rows = buckets.get(key)!
    const date = new Date(key)
    const total = sum(rows, (r) => r.request_count)
    const c4 = sum(rows.filter((r) => r.status_class === '4xx'), (r) => r.request_count)
    const c5 = sum(rows.filter((r) => r.status_class === '5xx'), (r) => r.request_count)
    const weightedAvg = total > 0 ? sum(rows, (r) => r.avg_duration_ms * r.request_count) / total : 0
    const p95 = rows.reduce((m, r) => Math.max(m, r.p95_duration_ms), 0)

    requests.push({ group: 'requests', date, value: total })
    errorRate.push({ group: '4xx', date, value: total > 0 ? (c4 / total) * 100 : 0 })
    errorRate.push({ group: '5xx', date, value: total > 0 ? (c5 / total) * 100 : 0 })
    latency.push({ group: 'avg', date, value: Math.round(weightedAvg) })
    latency.push({ group: 'p95', date, value: p95 })
    transfer.push({ group: 'in', date, value: sum(rows, (r) => r.total_request_bytes) / MB })
    transfer.push({ group: 'out', date, value: sum(rows, (r) => r.total_response_bytes) / MB })
  }

  const totalRequests = sum(rollups, (r) => r.request_count)
  const totalErrors = sum(rollups, (r) => r.error_count)
  const avgLatencyMs = totalRequests > 0 ? sum(rollups, (r) => r.avg_duration_ms * r.request_count) / totalRequests : 0

  const epMap = new Map<string, { route: string; method: HttpMethod; requests: number; errors: number; p95: number }>()
  for (const r of rollups) {
    const k = `${r.method} ${r.route}`
    const e = epMap.get(k)
    if (e) {
      e.requests += r.request_count
      e.errors += r.error_count
      e.p95 = Math.max(e.p95, r.p95_duration_ms)
    } else {
      epMap.set(k, { route: r.route, method: r.method, requests: r.request_count, errors: r.error_count, p95: r.p95_duration_ms })
    }
  }
  const topEndpoints: TopEndpoint[] = [...epMap.values()]
    .sort((a, b) => b.requests - a.requests)
    .slice(0, 10)
    .map((e) => ({
      route: e.route || '(unmatched)',
      method: e.method,
      requests: e.requests,
      error_rate: e.requests > 0 ? e.errors / e.requests : 0,
      p95_ms: e.p95,
    }))

  return {
    requests,
    errorRate,
    latency,
    transfer,
    kpis: {
      totalRequests,
      errorRate: totalRequests > 0 ? totalErrors / totalRequests : 0,
      avgLatencyMs: Math.round(avgLatencyMs),
      p95Ms: rollups.reduce((m, r) => Math.max(m, r.p95_duration_ms), 0),
      bytesIn: sum(rollups, (r) => r.total_request_bytes),
      bytesOut: sum(rollups, (r) => r.total_response_bytes),
      uniqueUsers: rollups.reduce((m, r) => Math.max(m, r.unique_users), 0),
    },
    topEndpoints,
  }
}

// ── Real platform charts — from the Commander usage-snapshot cache (GET /usage/platform) ──

export interface PlatformSeries {
  requests: { data: Point[]; groups: string[] } // stacked: top-4 packages + 'other'
  errorRate: Point[] // group 'error %'
}

export function snapshotsToPlatformSeries(snapshots: UsageSnapshot[], packageLabels: ReadonlyMap<number, string> = new Map()): PlatformSeries {
  // Pick the top-4 packages by total requests; everything else folds into 'other'.
  // Keyed by numeric package_id internally; chart labels use operator-facing package names.
  const totals = new Map<number, number>()
  for (const s of snapshots) totals.set(s.package_id, (totals.get(s.package_id) ?? 0) + Number(s.request_count))
  const top = [...totals.entries()].sort((a, b) => b[1] - a[1]).slice(0, 4).map(([id]) => id)
  const topSet = new Set(top)
  const labelFor = (id: number) => packageLabels.get(id) ?? `package ${id}`
  const groups = [...top.map(labelFor), 'other']

  const buckets = new Map<string, { perPkg: Map<number, number>; other: number; total: number; errors: number }>()
  for (const s of snapshots) {
    // The API serialises these aggregate columns as strings; coerce so the stacked sums
    // are numeric (string `+=` would concatenate and blow the chart's y-axis to nonsense).
    const requests = Number(s.request_count)
    const errors = Number(s.error_count)
    let b = buckets.get(s.period_start)
    if (!b) {
      b = { perPkg: new Map(), other: 0, total: 0, errors: 0 }
      buckets.set(s.period_start, b)
    }
    b.total += requests
    b.errors += errors
    if (topSet.has(s.package_id)) b.perPkg.set(s.package_id, (b.perPkg.get(s.package_id) ?? 0) + requests)
    else b.other += requests
  }

  const data: Point[] = []
  const errorRate: Point[] = []
  for (const key of [...buckets.keys()].sort()) {
    const b = buckets.get(key)!
    const date = new Date(key)
    for (const id of top) data.push({ group: labelFor(id), date, value: b.perPkg.get(id) ?? 0 })
    data.push({ group: 'other', date, value: b.other })
    errorRate.push({ group: 'error %', date, value: b.total > 0 ? (b.errors / b.total) * 100 : 0 })
  }

  return { requests: { data, groups }, errorRate }
}
