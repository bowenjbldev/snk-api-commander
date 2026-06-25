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

// Breakdown donut (status mix, invoices by status). Carbon legend at the bottom; colours come from
// the explicit semantic scale passed in.
export function donutOptions(theme: ThemeName, scale: Record<string, string>, height = '220px'): Opt {
  return {
    theme,
    height,
    toolbar: { enabled: false },
    animations: false,
    legend: { enabled: true, position: 'bottom' },
    donut: { alignment: 'center' },
    color: { scale },
  }
}

// Breakdown bar (requests/MRR by package). `horizontal` puts labels on the left axis (bars grow
// right); otherwise labels go on the bottom axis (vertical columns). One colour per package.
export function barOptions(theme: ThemeName, scale: Record<string, string>, height = '220px', horizontal = true): Opt {
  const axes = horizontal
    ? { left: { mapsTo: 'group', scaleType: 'labels' }, bottom: { mapsTo: 'value', includeZero: true } }
    : { bottom: { mapsTo: 'group', scaleType: 'labels' }, left: { mapsTo: 'value', includeZero: true } }
  return {
    theme,
    height,
    toolbar: { enabled: false },
    animations: false,
    legend: { enabled: false },
    axes,
    color: { scale },
    grid: horizontal ? { y: { enabled: false } } : { x: { enabled: false } },
  }
}

// Breakdown meter (invoices by status) — one proportional bar split into segments + a legend.
export function meterOptions(theme: ThemeName, scale: Record<string, string>, total: number, height = '200px'): Opt {
  return {
    theme,
    height,
    toolbar: { enabled: false },
    animations: false,
    color: { scale },
    meter: { proportional: { total } },
    legend: { enabled: true },
  }
}

// Breakdown lollipop (MRR by package) — horizontal stems with a dot at each value.
export function lollipopOptions(theme: ThemeName, scale: Record<string, string>, height = '220px'): Opt {
  return {
    theme,
    height,
    toolbar: { enabled: false },
    animations: false,
    legend: { enabled: false },
    points: { enabled: true },
    axes: {
      left: { mapsTo: 'group', scaleType: 'labels' },
      bottom: { mapsTo: 'value', includeZero: true },
    },
    color: { scale },
    grid: { y: { enabled: false } },
  }
}

// Contribution heatmap (Insights tab): week (x) × weekday (y) grid, cell colour = commit count.
// Both axes are categorical (`labels`); the colour gradient is theme-driven (Carbon default).
export function heatmapOptions(theme: ThemeName, height = '240px'): Opt {
  return {
    theme,
    height,
    toolbar: { enabled: false },
    animations: false,
    axes: {
      bottom: { title: '', mapsTo: 'week', scaleType: 'labels' },
      left: { title: '', mapsTo: 'day', scaleType: 'labels' },
    },
    heatmap: { colorLegend: { title: 'commits' } },
    grid: { x: { enabled: false }, y: { enabled: false } },
  }
}

// Weekly stacked bars over a real time axis (Insights code frequency). Deletions arrive negative so
// they sit below zero. Unlike stackedBarOptions, the x-axis is a generic time scale (not the 48h pin).
export function weeklyBarOptions(theme: ThemeName, scale: Record<string, string>, height = '260px'): Opt {
  return {
    theme,
    height,
    toolbar: { enabled: false },
    animations: false,
    legend: { enabled: false },
    bars: { width: 10, maxWidth: 1000 },
    axes: {
      left: { mapsTo: 'value', stacked: true, title: '' },
      bottom: { mapsTo: 'date', scaleType: 'time' },
    },
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
