// Deterministic chart series, ported from the prototype's seeded generator so
// the demo data is stable and matches the mock. Shaped for @carbon/charts:
// each point is { group, key (ISO time), value }.
import { DEMO_NOW_MS, HOUR } from '../lib/time'

export interface ChartPoint {
  group: string
  key: string
  value: number
}

// Park–Miller LCG — identical sequence to the prototype's rnd().
function rng(seed: number): () => number {
  let s = seed % 2147483647
  if (s <= 0) s += 2147483646
  return () => (s = (s * 16807) % 2147483647) / 2147483646
}

function gen(
  seed: number,
  n: number,
  base: number,
  amp: number,
  noise: number,
  spikeAt?: number,
  spikeV?: number,
): number[] {
  const r = rng(seed)
  const out: number[] = []
  for (let i = 0; i < n; i++) {
    let v =
      base +
      amp * Math.sin((((i % 24) - 7) / 24) * Math.PI * 2) +
      noise * (r() - 0.5) * 2
    if (spikeAt != null && spikeV != null) {
      const d = Math.abs(i - spikeAt)
      if (d <= 3) v += spikeV * (1 - d / 4)
    }
    out.push(Math.max(0, v))
  }
  return out
}

/** Map a numeric series onto `n` hourly buckets ending at the demo instant. */
function toPoints(values: number[], group: string, round = false): ChartPoint[] {
  const n = values.length
  return values.map((v, i) => ({
    group,
    key: new Date(DEMO_NOW_MS - (n - 1 - i) * HOUR).toISOString(),
    value: round ? Math.round(v) : Math.round(v * 100) / 100,
  }))
}

// --- Dashboard: requests/hour, 48h, stacked by top-4 deployments + other ---
export const REQUESTS_48H_GROUPS = [
  'helios-meter',
  'bluepeak',
  'bluepeak-eu',
  'northwind-api',
  'other',
] as const

export const requests48hStacked: ChartPoint[] = [
  ...toPoints(gen(11, 48, 48000, 16000, 5000, 38, 24000), 'helios-meter', true),
  ...toPoints(gen(12, 48, 9200, 3200, 1600), 'bluepeak', true),
  ...toPoints(gen(13, 48, 7600, 2600, 1300), 'bluepeak-eu', true),
  ...toPoints(gen(14, 48, 6400, 2200, 1100), 'northwind-api', true),
  ...toPoints(gen(15, 48, 5200, 1600, 1500), 'other', true),
]

export const REQUESTS_48H_COLORS: Record<string, string> = {
  'helios-meter': '#1192e8',
  bluepeak: '#6929c4',
  'bluepeak-eu': '#005d5d',
  'northwind-api': '#9f1853',
  other: '#8d8d8d',
}

// --- Dashboard: error rate 4xx+5xx, 48h, line + area ---
export const errorRate48h: ChartPoint[] = toPoints(
  gen(21, 48, 1.4, 0.5, 0.7, 38, 5.4),
  'Error rate',
)
export const ERROR_RATE_COLORS = { 'Error rate': '#fa4d56' }
/** Peak annotation copy for the error-rate chart. */
export const ERROR_RATE_PEAK = 'peak 6.8% — helios-meter 429 burst'

// --- Usage tab (kestrel-track), 7 days hourly ---
const N7D = 168

export const usageRequests: ChartPoint[] = toPoints(
  gen(31, N7D, 820, 280, 140),
  'Requests',
  true,
)
export const USAGE_REQUESTS_COLORS = { Requests: '#6929c4' }

export const usageErrorRate: ChartPoint[] = [
  ...toPoints(gen(32, N7D, 1.5, 0.5, 0.6), '4xx'),
  ...toPoints(gen(33, N7D, 0.3, 0.15, 0.25, 118, 2.6), '5xx'),
]
export const USAGE_ERROR_COLORS = { '4xx': '#d2a106', '5xx': '#fa4d56' }

export const usageLatency: ChartPoint[] = [
  ...toPoints(gen(34, N7D, 128, 20, 16), 'avg', true),
  ...toPoints(gen(35, N7D, 470, 60, 45, 118, 170), 'p95', true),
]
export const USAGE_LATENCY_COLORS = { avg: '#1192e8', p95: '#9f1853' }
/** p95 renders dashed in the mock. */
export const USAGE_LATENCY_DASHED = ['p95']

export const usageTransfer: ChartPoint[] = [
  ...toPoints(gen(36, N7D, 12, 3, 2.5), 'in', true),
  ...toPoints(gen(37, N7D, 44, 12, 6), 'out', true),
]
export const USAGE_TRANSFER_COLORS = { in: '#005d5d', out: '#6929c4' }
