import { describe, it, expect } from 'vitest'
import {
  formatInt,
  formatCompact,
  formatBytes,
  formatMb,
  formatCurrency,
  formatCurrencyShort,
  formatPercent,
} from './format'

describe('format', () => {
  it('integers with thousands separators', () => {
    expect(formatInt(1240332)).toBe('1,240,332')
  })
  it('compact', () => {
    expect(formatCompact(1658472)).toBe('1.7M')
    expect(formatCompact(138400)).toBe('138.4k')
    expect(formatCompact(412)).toBe('412')
  })
  it('bytes', () => {
    expect(formatBytes(512)).toBe('512 B')
    expect(formatBytes(18842)).toBe('18.4 KB')
  })
  it('megabytes', () => {
    expect(formatMb(512)).toBe('512 MB')
    expect(formatMb(8920)).toBe('8.7 GB') // ≥1024 MB rolls up to GB
    expect(formatMb(2048)).toBe('2 GB')
  })
  it('currency', () => {
    expect(formatCurrency(520)).toBe('$520.00')
    expect(formatCurrencyShort(3420)).toBe('$3,420')
  })
  it('percent', () => {
    expect(formatPercent(1.24)).toBe('124%')
    expect(formatPercent(0.018, 1)).toBe('1.8%')
  })
})
