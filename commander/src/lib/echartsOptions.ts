// ECharts option builders. ECharts doesn't follow Carbon's theme, so we theme manually with Carbon
// tokens (g100 dark / g10 light). Canvas renderer + animations:false keeps dense data smooth, and a
// real `time` x-axis lets ECharts auto-pick ticks per span (hours for 48h, months for a year) — no
// hand-pinned tick values. `legendItems` (lib-agnostic) feeds the custom legend in ChartCard.
/* eslint-disable @typescript-eslint/no-explicit-any */
import { OTHER_COLOR, PALETTE, type HeatPoint, type Point } from './chartData'
import type { ThemeName } from '../providers/ThemeProvider'

type EOption = any

const axisColors = (dark: boolean) => ({
  text: dark ? '#c6c6c6' : '#525252',
  split: dark ? '#393939' : '#e0e0e0',
})
const tooltipBox = (dark: boolean) => ({
  backgroundColor: dark ? '#393939' : '#ffffff',
  borderWidth: 0,
  textStyle: { color: dark ? '#f4f4f4' : '#161616', fontSize: 12 },
})

// Custom legend swatches (rendered in the card header by ChartCard, not by the chart itself).
export function legendItems(groups: string[]): { label: string; color: string }[] {
  return groups.map((g, i) => ({ label: g, color: g === 'other' ? OTHER_COLOR : PALETTE[i % PALETTE.length] }))
}

export interface TimeSeriesOpts {
  theme: ThemeName
  type: 'bar' | 'line'
  area?: boolean // line only — fill under the curve (→ area / stacked-area chart)
  stack?: boolean // stack groups
  scale?: Record<string, string> // explicit group→colour; else categorical palette by index
  groups?: string[] // explicit group order (stacking + colour); else first-seen order
  showX?: boolean // show the time axis (dashboard hides it; detail tabs show it)
  yPercent?: boolean // y is a 0..100 percentage (axis suffix + tooltip)
  barMaxWidth?: number
}

// Pivot the flat {group,date,value} series into one ECharts series per group, aligned on the sorted
// unique timestamps, plotted on a real time axis as [ts, value] pairs.
export function timeSeriesOption(data: Point[], o: TimeSeriesOpts): EOption {
  const dark = o.theme === 'g100'
  const c = axisColors(dark)
  const dates = [...new Set(data.map((p) => +p.date))].sort((a, b) => a - b)
  const idx = new Map(dates.map((d, i) => [d, i]))
  const groups = o.groups ?? [...new Set(data.map((p) => p.group))]
  const colorFor = (g: string, gi: number) => (o.scale ? o.scale[g] : g === 'other' ? OTHER_COLOR : PALETTE[gi % PALETTE.length])
  const series = groups.map((g, gi) => {
    const vals = new Array<number>(dates.length).fill(0)
    for (const p of data) if (p.group === g) vals[idx.get(+p.date)!] = p.value
    const color = colorFor(g, gi)
    return {
      name: g,
      type: o.type,
      stack: o.stack ? 'total' : undefined,
      smooth: o.type === 'line' ? 0.25 : undefined,
      showSymbol: false,
      // LTTB canvas downsampling for long non-stacked lines (stacked series must keep every point
      // aligned, so we skip it there); a no-op when points ≤ pixels.
      sampling: o.type === 'line' && !o.stack ? 'lttb' : undefined,
      // Bars fill their time-slot (no fixed max width) so few bars (24h/48h) grow wide instead of
      // leaving gaps, while many bars (a year) stay thin — a small category gap keeps them legible.
      barCategoryGap: o.type === 'bar' ? '8%' : undefined,
      barMaxWidth: o.barMaxWidth,
      itemStyle: { color },
      lineStyle: o.type === 'line' ? { width: 1.5, color } : undefined,
      areaStyle: o.type === 'line' && o.area ? { color, opacity: dark ? 0.35 : 0.2 } : undefined,
      data: dates.map((d, i) => [d, vals[i]]),
    }
  })
  return {
    animation: false,
    backgroundColor: 'transparent',
    grid: { top: 8, right: 8, bottom: o.showX ? 24 : 8, left: 8, containLabel: true },
    tooltip: {
      trigger: 'axis',
      ...tooltipBox(dark),
      valueFormatter: o.yPercent ? (v: any) => `${Number(Array.isArray(v) ? v[1] : v).toFixed(2)}%` : undefined,
    },
    xAxis: {
      type: 'time',
      show: !!o.showX,
      axisLabel: { color: c.text, hideOverlap: true, fontSize: 11 },
      axisLine: { lineStyle: { color: c.split } },
      splitLine: { show: false },
    },
    yAxis: {
      type: 'value',
      axisLabel: { color: c.text, fontSize: 11, formatter: o.yPercent ? '{value}%' : undefined },
      splitLine: { lineStyle: { color: c.split } },
    },
    series,
  }
}

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

// GitHub-style contribution heatmap (replaces Carbon's HeatmapChart): weeks (x) × weekday (y), cell
// colour = commit count via a green visualMap ramp. `dailyToHeatmap` emits weeks chronologically.
export function heatmapOption(data: HeatPoint[], theme: ThemeName): EOption {
  const dark = theme === 'g100'
  const c = axisColors(dark)
  const weeks = [...new Set(data.map((d) => d.week))]
  const wIdx = new Map(weeks.map((w, i) => [w, i]))
  const dIdx = new Map(WEEKDAYS.map((d, i) => [d, i]))
  const max = data.reduce((m, d) => Math.max(m, d.value), 0)
  const cells = data.map((d) => [wIdx.get(d.week)!, dIdx.get(d.day)!, d.value])
  return {
    animation: false,
    backgroundColor: 'transparent',
    grid: { top: 8, right: 8, bottom: 28, left: 8, containLabel: true },
    tooltip: {
      position: 'top',
      ...tooltipBox(dark),
      formatter: (p: any) => {
        const [x, y, v] = p.value
        return `${weeks[x]} · ${WEEKDAYS[y]}<br/>${v} commit${v === 1 ? '' : 's'}`
      },
    },
    xAxis: {
      type: 'category',
      data: weeks,
      axisLabel: { color: c.text, fontSize: 10, hideOverlap: true, interval: Math.ceil(weeks.length / 12) },
      axisLine: { show: false },
      axisTick: { show: false },
      splitArea: { show: false },
    },
    yAxis: {
      type: 'category',
      data: WEEKDAYS,
      inverse: true, // Mon at top, like GitHub
      axisLabel: { color: c.text, fontSize: 10 },
      axisLine: { show: false },
      axisTick: { show: false },
      splitArea: { show: false },
    },
    visualMap: {
      min: 0,
      max: max || 1,
      calculable: false,
      orient: 'horizontal',
      right: 8,
      bottom: 0,
      itemWidth: 12,
      itemHeight: 80,
      text: ['more', 'less'],
      textStyle: { color: c.text, fontSize: 10 },
      inRange: {
        color: dark
          ? ['#161616', '#0e4429', '#006d32', '#26a641', '#39d353']
          : ['#ebedf0', '#9be9a8', '#40c463', '#30a14e', '#216e39'],
      },
    },
    series: [
      {
        type: 'heatmap',
        data: cells,
        itemStyle: { borderColor: dark ? '#262626' : '#ffffff', borderWidth: 2 },
        emphasis: { itemStyle: { borderColor: dark ? '#525252' : '#8d8d8d' } },
      },
    ],
  }
}
