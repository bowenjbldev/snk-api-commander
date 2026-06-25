// Generic ECharts host. Init once, keep the canvas sized to its (often flex) container via a
// ResizeObserver, re-feed the option on change (notMerge=true drops stale series on a data swap),
// dispose on unmount. Option builders live in lib/echartsOptions.ts. We use ECharts (canvas) rather
// than @carbon/charts (SVG) because SVG had no virtualization and choked on dense data.
/* eslint-disable @typescript-eslint/no-explicit-any */
import { useEffect, useRef } from 'react'
import * as echarts from 'echarts/core'
import { BarChart, LineChart, HeatmapChart } from 'echarts/charts'
import { GridComponent, TooltipComponent, VisualMapComponent } from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'

echarts.use([BarChart, LineChart, HeatmapChart, GridComponent, TooltipComponent, VisualMapComponent, CanvasRenderer])

export function EChart({ option, height = '100%' }: { option: any; height?: number | string }) {
  const elRef = useRef<HTMLDivElement>(null)
  const chartRef = useRef<ReturnType<typeof echarts.init> | null>(null)

  useEffect(() => {
    if (!elRef.current) return
    const chart = echarts.init(elRef.current, undefined, { renderer: 'canvas' })
    chartRef.current = chart
    const ro = new ResizeObserver(() => chart.resize())
    ro.observe(elRef.current)
    return () => {
      ro.disconnect()
      chart.dispose()
      chartRef.current = null
    }
  }, [])

  useEffect(() => {
    chartRef.current?.setOption(option, true)
  }, [option])

  return <div ref={elRef} style={{ width: '100%', height, minHeight: typeof height === 'number' ? undefined : 240 }} />
}
