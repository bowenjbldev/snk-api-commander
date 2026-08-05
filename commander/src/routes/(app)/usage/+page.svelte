<script lang="ts">
	import { usage } from '$lib/api/resources';
	import { meterFromQuota, worstMeter } from '$lib/domain/meter';
	import { k } from '$lib/format';
	import { TimeWindow } from '$lib/model/enums';
	import { displayName } from '$lib/model/situation';
	import type { CommanderUsageBucket } from '$lib/model/types';
	import PageHeader from '$lib/shell/PageHeader.svelte';
	import { data } from '$lib/state/data.svelte';
	import Card from '$lib/ui/Card.svelte';
	import Legend from '$lib/ui/Legend.svelte';
	import Segmented from '$lib/ui/Segmented.svelte';
	import TopConsumers from './TopConsumers.svelte';
	import EChart from '$lib/viz/EChart.svelte';
	import { errorRate, requestsByInstance, toTraffic } from '$lib/viz/options/traffic';
	import { seriesCssVar } from '$lib/viz/theme';

	const WINDOWS = [
		{ value: TimeWindow.Hours48, label: '48 h' },
		{ value: TimeWindow.Week, label: 'Semana' },
		{ value: TimeWindow.Month, label: 'Mes' }
	];
	const HOURS: Record<string, number> = {
		[TimeWindow.Hours48]: 48,
		[TimeWindow.Week]: 24 * 7,
		[TimeWindow.Month]: 24 * 30
	};

	let window = $state<string>(TimeWindow.Hours48);
	let buckets = $state<CommanderUsageBucket[]>([]);
	let failed = $state(false);

	$effect(() => {
		usage
			.platform(HOURS[window] ?? 48)
			.then((rows) => {
				buckets = rows;
				failed = false;
			})
			.catch(() => {
				buckets = [];
				failed = true;
			});
	});

	const names = $derived(new Map(data.packages.map((pkg) => [pkg.id, displayName(pkg)])));
	const traffic = $derived(toTraffic(buckets, (id) => names.get(id) ?? `#${id}`));
	const requests = $derived(buckets.reduce((total, row) => total + row.request_count, 0));

	const top = $derived.by(() => {
		// eslint-disable-next-line svelte/prefer-svelte-reactivity -- se construye y se descarta aquí
		const totals = new Map<number, number>();
		for (const row of buckets) {
			totals.set(row.package_id, (totals.get(row.package_id) ?? 0) + row.request_count);
		}
		return [...totals.entries()]
			.sort((a, b) => b[1] - a[1])
			.slice(0, 8)
			.flatMap(([id, count]) => {
				const pkg = data.packages.find((p) => p.id === id);
				if (!pkg) return [];
				const quotas = pkg.quotas ?? [];
				return [
					{
						id,
						name: displayName(pkg),
						client: pkg.client?.company ?? pkg.client?.name ?? '—',
						requests: k(count),
						plan: pkg.subscription?.plan ?? '—',
						meter: worstMeter(quotas) ?? (quotas[0] ? meterFromQuota(quotas[0]) : null)
					}
				];
			});
	});
</script>

<svelte:head><title>Commander · Uso agregado</title></svelte:head>

<PageHeader title="Uso agregado" subtitle={k(requests)}>
	{#snippet action()}
		<Segmented bind:value={window} options={WINDOWS} label="Ventana de tiempo" />
	{/snippet}
</PageHeader>

<section>
	<p class="lede">La misma pregunta que el uso de una instancia, para toda la plataforma.</p>

	<div class="charts">
		<Card title="Peticiones por instancia" note={k(requests)}>
			{#snippet aside()}
				<Legend
					items={traffic.series.map((serie, i) => ({ label: serie.name, color: seriesCssVar(i) }))}
				/>
			{/snippet}
			{#if failed}
				<p class="failed">No se pudo leer la serie de tráfico.</p>
			{:else}
				<EChart build={requestsByInstance(traffic)} label="Peticiones por instancia" height={220} />
			{/if}
		</Card>

		<Card title="Proporción de error">
			{#if failed}
				<p class="failed">No se pudo leer la serie de tráfico.</p>
			{:else}
				<EChart build={errorRate(buckets)} label="Proporción de error" height={220} />
			{/if}
		</Card>
	</div>

	<TopConsumers rows={top} />
</section>

<style>
	section {
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding: 14px 16px 28px;
	}

	.lede {
		margin: 0;
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.charts {
		display: grid;
		grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr);
		gap: 12px;
	}

	.failed {
		margin: 0;
		padding: 60px 0;
		text-align: center;
		font-size: var(--fs-body);
		color: var(--tx3);
	}
</style>
