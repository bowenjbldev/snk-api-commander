import type { EChartsOption } from 'echarts';
import { k, stamp } from '$lib/format';
import type { CommanderUsageBucket } from '$lib/model/types';
import { seriesColor, type ChartPalette } from '../theme';

export interface TrafficSeries {
	name: string;
	values: number[];
}

export interface TrafficData {
	buckets: string[];
	series: TrafficSeries[];
}

/** Las cinco instancias que más piden, y el resto agregado en una sexta. */
const TOP = 5;

export function toTraffic(
	rows: readonly CommanderUsageBucket[],
	nameOf: (packageId: number) => string
): TrafficData {
	const buckets = [...new Set(rows.map((row) => row.period_start))].sort();
	const index = new Map(buckets.map((bucket, i) => [bucket, i]));

	const totals = new Map<number, number>();
	for (const row of rows)
		totals.set(row.package_id, (totals.get(row.package_id) ?? 0) + row.request_count);

	const top = [...totals.entries()]
		.sort((a, b) => b[1] - a[1])
		.slice(0, TOP)
		.map(([id]) => id);

	const empty = (): number[] => Array.from({ length: buckets.length }, () => 0);
	const named = new Map(top.map((id) => [id, empty()]));
	const rest = empty();

	for (const row of rows) {
		const at = index.get(row.period_start);
		if (at === undefined) continue;
		const target = named.get(row.package_id) ?? rest;
		target[at] = (target[at] ?? 0) + row.request_count;
	}

	const series: TrafficSeries[] = top.map((id) => ({
		name: nameOf(id),
		values: named.get(id) ?? empty()
	}));
	if (rest.some((value) => value > 0)) series.push({ name: 'resto de la flota', values: rest });

	return { buckets, series };
}

/** Peticiones por instancia, apiladas. Ningún color escrito aquí: todos salen de la paleta. */
export function requestsByInstance(data: TrafficData) {
	return (palette: ChartPalette): EChartsOption => ({
		grid: { top: 12, right: 8, bottom: 24, left: 44 },
		tooltip: {
			trigger: 'axis',
			backgroundColor: palette.card,
			borderColor: palette.border,
			textStyle: { color: palette.text, fontFamily: palette.fontUi, fontSize: 12 }
		},
		xAxis: {
			type: 'category',
			data: data.buckets.map((iso) => stamp(iso)),
			axisLine: { lineStyle: { color: palette.borderSubtle } },
			axisTick: { show: false },
			axisLabel: { color: palette.text3, fontFamily: palette.fontMono, fontSize: 10 }
		},
		yAxis: {
			type: 'value',
			splitLine: { lineStyle: { color: palette.borderSubtle } },
			axisLabel: {
				color: palette.text3,
				fontFamily: palette.fontMono,
				fontSize: 10,
				formatter: (value: number) => k(value)
			}
		},
		series: data.series.map((serie, i) => ({
			name: serie.name,
			type: 'line',
			stack: 'total',
			areaStyle: { opacity: 0.75 },
			showSymbol: false,
			lineStyle: { width: 1 },
			itemStyle: { color: seriesColor(palette, i) },
			data: serie.values
		}))
	});
}

/** Proporción de error 4xx/5xx. Aquí la serie ES un estado, así que va en color semántico. */
export function errorRate(rows: readonly CommanderUsageBucket[]) {
	const buckets = [...new Set(rows.map((row) => row.period_start))].sort();
	const at = new Map(buckets.map((bucket, i) => [bucket, i]));
	const c4 = Array.from({ length: buckets.length }, () => 0);
	const c5 = Array.from({ length: buckets.length }, () => 0);

	for (const row of rows) {
		const i = at.get(row.period_start);
		if (i === undefined) continue;
		c4[i] = (c4[i] ?? 0) + row.count_4xx;
		c5[i] = (c5[i] ?? 0) + row.count_5xx;
	}

	return (palette: ChartPalette): EChartsOption => ({
		grid: { top: 12, right: 8, bottom: 24, left: 40 },
		tooltip: {
			trigger: 'axis',
			backgroundColor: palette.card,
			borderColor: palette.border,
			textStyle: { color: palette.text, fontFamily: palette.fontUi, fontSize: 12 }
		},
		xAxis: {
			type: 'category',
			data: buckets.map((iso) => stamp(iso)),
			axisLine: { lineStyle: { color: palette.borderSubtle } },
			axisTick: { show: false },
			axisLabel: { color: palette.text3, fontFamily: palette.fontMono, fontSize: 10 }
		},
		yAxis: {
			type: 'value',
			splitLine: { lineStyle: { color: palette.borderSubtle } },
			axisLabel: { color: palette.text3, fontFamily: palette.fontMono, fontSize: 10 }
		},
		series: [
			{ name: '4xx', type: 'bar', stack: 'err', itemStyle: { color: palette.warn }, data: c4 },
			{ name: '5xx', type: 'bar', stack: 'err', itemStyle: { color: palette.error }, data: c5 }
		]
	});
}
