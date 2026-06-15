// @carbon/charts option builders. The library does NOT follow Carbon's global theme,
// so we pass `theme` explicitly and rebuild (useMemo) on theme change. We disable the
// built-in legend and render a custom one in the card header (top-right, by the title).
//
// To make EVERY chart's x-axis identical (Carbon otherwise auto-picks different ticks
// per chart type — bars thin more than areas), we pin explicit tick values aligned to
// 12h UTC boundaries over the last 48h: a date label at midnight ("Jun 13"), the hour
// otherwise ("12:00"). Carbon's options types are large; we keep these loose.
import type { ThemeName } from '../providers/ThemeProvider'
import { OTHER_COLOR, PALETTE } from './chartData'

/* eslint-disable @typescript-eslint/no-explicit-any */
type Opt = any

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const HALF_DAY = 12 * 3_600_000

function xTickValues(): Date[] {
  const now = Date.now()
  const start = now - 47 * 3_600_000
  // Multiples of 12h since the epoch land exactly on 00:00 / 12:00 UTC.
  const first = Math.ceil(start / HALF_DAY) * HALF_DAY
  const out: Date[] = []
  for (let t = first; t <= now; t += HALF_DAY) out.push(new Date(t))
  return out
}

function tickFmt(tick: unknown): string {
  const d = tick instanceof Date ? tick : new Date(tick as number)
  if (Number.isNaN(d.getTime())) return ''
  const h = d.getUTCHours()
  if (h === 0) return `${MONTHS[d.getUTCMonth()]} ${d.getUTCDate()}`
  return `${String(h).padStart(2, '0')}:00`
}

const timeBottom = () => ({
  title: '',
  mapsTo: 'date',
  scaleType: 'time',
  ticks: { values: xTickValues(), formatter: tickFmt },
})

export function legendItems(groups: string[]): { label: string; color: string }[] {
  return groups.map((g, i) => ({ label: g, color: g === 'other' ? OTHER_COLOR : PALETTE[i % PALETTE.length] }))
}

export function stackedBarOptions(theme: ThemeName, groups: string[], height = '320px'): Opt {
  const scale: Record<string, string> = {}
  groups.forEach((g, i) => {
    scale[g] = g === 'other' ? OTHER_COLOR : PALETTE[i % PALETTE.length]
  })
  return {
    theme,
    height,
    toolbar: { enabled: false },
    animations: false,
    legend: { enabled: false },
    // Thicker bars: fixed 12px width (band ≈14.5px over 48 hourly bars).
    bars: { width: 12, maxWidth: 1000 },
    axes: { left: { mapsTo: 'value', stacked: true, title: '' }, bottom: timeBottom() },
    color: { scale },
    grid: { x: { enabled: false } },
  }
}

export function lineOptions(theme: ThemeName, scale: Record<string, string>, height = '260px'): Opt {
  return {
    theme,
    height,
    toolbar: { enabled: false },
    animations: false,
    points: { enabled: false },
    legend: { enabled: false },
    curve: 'curveMonotoneX',
    axes: { left: { mapsTo: 'value', title: '' }, bottom: timeBottom() },
    color: { scale },
    grid: { x: { enabled: false } },
  }
}

export function areaOptions(theme: ThemeName, scale: Record<string, string>, height = '260px'): Opt {
  return {
    theme,
    height,
    toolbar: { enabled: false },
    animations: false,
    points: { enabled: false },
    legend: { enabled: false },
    curve: 'curveMonotoneX',
    axes: { left: { mapsTo: 'value', title: '' }, bottom: timeBottom() },
    color: { scale },
    grid: { x: { enabled: false } },
  }
}
