import {
  AreaChart,
  LineChart,
  StackedBarChart,
} from '@carbon/charts-react'
import { useTheme } from '../../theme/ThemeContext'
import type { ChartPoint } from '../../data/charts'

type LeftFormatter = (v: number) => string

function baseOptions(
  theme: 'g100' | 'g10',
  colors: Record<string, string>,
  height: number,
  leftFormatter?: LeftFormatter,
) {
  return {
    theme,
    height: `${height}px`,
    color: { scale: colors },
    legend: { enabled: false },
    toolbar: { enabled: false },
    tooltip: { showTotal: false },
    axes: {
      left: {
        mapsTo: 'value',
        scaleType: 'linear',
        ticks: leftFormatter ? { formatter: leftFormatter } : undefined,
      },
      bottom: {
        mapsTo: 'key',
        scaleType: 'time',
        ticks: { number: 5 },
      },
    },
    grid: { x: { enabled: false } },
  }
}

/** Small custom legend (9px swatches + mono labels) for chart card headers. */
export function ChartLegend({
  items,
}: {
  items: { label: string; color: string; dashed?: boolean }[]
}) {
  return (
    <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap' }}>
      {items.map((it) => (
        <span
          key={it.label}
          style={{ display: 'flex', alignItems: 'center', gap: 5 }}
        >
          <span
            style={{
              width: 9,
              height: it.dashed ? 0 : 9,
              borderTop: it.dashed ? `2px dashed ${it.color}` : undefined,
              background: it.dashed ? undefined : it.color,
              display: 'inline-block',
            }}
          />
          <span
            className="sc-mono"
            style={{ fontSize: 11, color: 'var(--cds-text-secondary)' }}
          >
            {it.label}
          </span>
        </span>
      ))}
    </div>
  )
}

export function ScStackedBar({
  data,
  colors,
  height = 218,
  leftFormatter,
}: {
  data: ChartPoint[]
  colors: Record<string, string>
  height?: number
  leftFormatter?: LeftFormatter
}) {
  const { theme } = useTheme()
  const options = {
    ...baseOptions(theme, colors, height, leftFormatter),
    bars: { width: 8 },
  }
  return <StackedBarChart data={data} options={options as never} />
}

export function ScLine({
  data,
  colors,
  height = 190,
  leftFormatter,
}: {
  data: ChartPoint[]
  colors: Record<string, string>
  height?: number
  leftFormatter?: LeftFormatter
}) {
  const { theme } = useTheme()
  const options = {
    ...baseOptions(theme, colors, height, leftFormatter),
    points: { enabled: false },
    curve: 'curveMonotoneX',
  }
  return <LineChart data={data} options={options as never} />
}

export function ScArea({
  data,
  colors,
  height = 190,
  leftFormatter,
}: {
  data: ChartPoint[]
  colors: Record<string, string>
  height?: number
  leftFormatter?: LeftFormatter
}) {
  const { theme } = useTheme()
  const options = {
    ...baseOptions(theme, colors, height, leftFormatter),
    points: { enabled: false },
    curve: 'curveMonotoneX',
  }
  return <AreaChart data={data} options={options as never} />
}
