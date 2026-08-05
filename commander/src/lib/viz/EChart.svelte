<script lang="ts">
	import { BarChart, LineChart } from 'echarts/charts';
	import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
	import * as echarts from 'echarts/core';
	import { CanvasRenderer } from 'echarts/renderers';
	import type { EChartsOption } from 'echarts';
	import { theme } from '$lib/state/theme.svelte';
	import { readPalette, type ChartPalette } from './theme';

	// Por módulos y no el paquete entero: ECharts pesa lo que pesa.
	echarts.use([
		BarChart,
		LineChart,
		GridComponent,
		TooltipComponent,
		LegendComponent,
		CanvasRenderer
	]);

	interface Props {
		/** Recibe la paleta ya leída del DOM: ninguna función de opciones contiene un color. */
		build: (palette: ChartPalette) => EChartsOption;
		height?: number;
		label: string;
	}

	const { build, height = 180, label }: Props = $props();

	let host: HTMLDivElement;

	$effect(() => {
		const chart = echarts.init(host, undefined, { renderer: 'canvas' });
		const observer = new ResizeObserver(() => {
			chart.resize();
		});
		observer.observe(host);

		return () => {
			observer.disconnect();
			chart.dispose();
		};
	});

	$effect(() => {
		// Depende del tema a propósito: al alternarlo se reconstruye la paleta y se reaplica, así
		// no queda ningún color del tema anterior.
		void theme.value;
		const chart = echarts.getInstanceByDom(host);
		chart?.setOption(build(readPalette()), { notMerge: true });
	});
</script>

<div bind:this={host} class="chart" style:height="{height}px" role="img" aria-label={label}></div>

<style>
	.chart {
		width: 100%;
	}
</style>
