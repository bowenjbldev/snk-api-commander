// Shared time-range presets for the dashboard chart filters (per-card, set from each card's kebab).
// `days` = lookback window; `period` = bucket granularity for the usage endpoint (hourly for the
// short ranges, daily for week+ so a year doesn't return thousands of hourly points). `subtitle`
// is what each chart card shows so the applied filter is always visible.
export type RangeKey = '24h' | '48h' | 'week' | 'month' | 'year'

// Chart aggregation granularity — distinct from `period` (the API fetch granularity). The chart
// re-buckets the fetched rows so a long window never renders thousands of SVG marks: a year of
// daily rows collapses to ~52 weekly bars. For the short ranges `bucket === period`, so the
// re-bucket is a no-op and their output is byte-identical to before.
export type Bucket = 'hour' | 'day' | 'week' | 'month'

export interface TimeRange {
  key: RangeKey
  menu: string // label in the kebab menu
  subtitle: string // shown on the chart card
  days: number // lookback window in days
  period: 'hour' | 'day' // usage fetch granularity (sent to the API)
  bucket: Bucket // chart aggregation granularity (caps the mark count)
  refreshMs: number // poll cadence — tight for live hourly windows, relaxed for long ones
}

export const TIME_RANGES: TimeRange[] = [
  { key: '24h', menu: '24 hours', subtitle: 'last 24h', days: 1, period: 'hour', bucket: 'hour', refreshMs: 30_000 },
  { key: '48h', menu: '48 hours', subtitle: 'last 48h', days: 2, period: 'hour', bucket: 'hour', refreshMs: 30_000 },
  { key: 'week', menu: 'Week', subtitle: 'last 7 days', days: 7, period: 'day', bucket: 'day', refreshMs: 300_000 },
  { key: 'month', menu: 'Month', subtitle: 'last 30 days', days: 30, period: 'day', bucket: 'day', refreshMs: 300_000 },
  { key: 'year', menu: 'Year', subtitle: 'last 12 months', days: 365, period: 'day', bucket: 'week', refreshMs: 300_000 },
]

export const rangeOf = (k: RangeKey): TimeRange => TIME_RANGES.find((r) => r.key === k) ?? TIME_RANGES[1]
