// Shared time-range presets for the dashboard chart filters (per-card, set from each card's kebab).
// `days` = lookback window; `period` = bucket granularity for the usage endpoint (hourly for the
// short ranges, daily for week+ so a year doesn't return thousands of hourly points). `subtitle`
// is what each chart card shows so the applied filter is always visible.
export type RangeKey = '24h' | '48h' | 'week' | 'month' | 'year'

export interface TimeRange {
  key: RangeKey
  menu: string // label in the kebab menu
  subtitle: string // shown on the chart card
  days: number // lookback window in days
  period: 'hour' | 'day' // usage bucket granularity
}

export const TIME_RANGES: TimeRange[] = [
  { key: '24h', menu: '24 hours', subtitle: 'last 24h', days: 1, period: 'hour' },
  { key: '48h', menu: '48 hours', subtitle: 'last 48h', days: 2, period: 'hour' },
  { key: 'week', menu: 'Week', subtitle: 'last 7 days', days: 7, period: 'day' },
  { key: 'month', menu: 'Month', subtitle: 'last 30 days', days: 30, period: 'day' },
  { key: 'year', menu: 'Year', subtitle: 'last 12 months', days: 365, period: 'day' },
]

export const rangeOf = (k: RangeKey): TimeRange => TIME_RANGES.find((r) => r.key === k) ?? TIME_RANGES[1]
