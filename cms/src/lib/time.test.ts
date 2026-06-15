import { describe, it, expect } from 'vitest'
import {
  formatRelative,
  formatCountdownShort,
  formatCountdownClock,
  formatDateUTC,
  formatDayMonth,
  formatMonthYear,
} from './time'

const NOW = Date.UTC(2026, 5, 11, 14, 2, 0) // 2026-06-11 14:02 UTC
const iso = (ms: number) => new Date(NOW + ms).toISOString()
const H = 3_600_000
const M = 60_000

describe('formatRelative', () => {
  it('past hours and days', () => {
    expect(formatRelative(iso(-3 * H), NOW)).toBe('3h ago')
    expect(formatRelative(iso(-2 * 24 * H), NOW)).toBe('2d ago')
    expect(formatRelative(iso(-45 * M), NOW)).toBe('45m ago')
  })
  it('just now and future', () => {
    expect(formatRelative(iso(-10_000), NOW)).toBe('just now')
    expect(formatRelative(iso(5 * H), NOW)).toBe('in 5h')
  })
})

describe('countdowns', () => {
  it('short form', () => {
    expect(formatCountdownShort(13 * H + 42 * M)).toBe('13h 42m')
    expect(formatCountdownShort(5 * M)).toBe('5m')
    expect(formatCountdownShort(26 * H)).toBe('1d 2h')
    expect(formatCountdownShort(-1000)).toBe('0m')
  })
  it('clock form clamps at zero', () => {
    expect(formatCountdownClock(13 * H + 42 * M + 10_000)).toBe('13:42:10')
    expect(formatCountdownClock(-5000)).toBe('00:00:00')
  })
})

describe('absolute dates (UTC)', () => {
  it('formats', () => {
    expect(formatDateUTC('2026-06-12T09:00:00Z')).toBe('Jun 12, 2026 09:00 UTC')
    expect(formatDayMonth('2026-06-02T10:00:00Z')).toBe('Jun 02')
    expect(formatMonthYear('2024-03-04T10:00:00Z')).toBe('Mar 2024')
  })
})
