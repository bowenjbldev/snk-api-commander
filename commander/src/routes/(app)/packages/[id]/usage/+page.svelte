<script lang="ts">
	import { page } from '$app/state';
	import { instance } from '$lib/api/resources';
	import { k, pct } from '$lib/format';
	import { UsagePeriod } from '$lib/model/enums';
	import type { CommanderUsageBucket } from '$lib/model/types';
	import Card from '$lib/ui/Card.svelte';
	import Segmented from '$lib/ui/Segmented.svelte';
	import Stat from '$lib/ui/Stat.svelte';
	import EChart from '$lib/viz/EChart.svelte';
	import { errorRate, requestsByInstance, toTraffic } from '$lib/viz/options/traffic';

	const PERIODS = [
		{ value: UsagePeriod.Hour, label: 'Por hora' },
		{ value: UsagePeriod.Day, label: 'Por día' }
	];

	const id = $derived(Number(page.params['id']));
	let period = $state<UsagePeriod>(UsagePeriod.Hour);
	let buckets = $state<CommanderUsageBucket[]>([]);
	let failed = $state(false);

	$effect(() => {
		instance
			.usage(id, period)
			.then((rows) => {
				buckets = rows;
				failed = false;
			})
			.catch(() => {
				buckets = [];
				failed = true;
			});
	});

	const requests = $derived(buckets.reduce((total, row) => total + row.request_count, 0));
	const errors = $derived(buckets.reduce((total, row) => total + row.error_count, 0));
	const traffic = $derived(toTraffic(buckets, () => 'esta instancia'));
</script>

<section>
	<div class="head">
		<Segmented bind:value={period} options={PERIODS} label="Granularidad" />
		<span class="note">
			Agregados {period === UsagePeriod.Hour ? 'horarios' : 'diarios'}; la lectura puede llevar
			hasta una hora de retraso.
		</span>
	</div>

	<div class="stats">
		<Stat label="Peticiones" value={k(requests)} />
		<Stat
			label="Proporción de error"
			value={requests ? pct(errors / requests) : '—'}
			subValue="4xx+5xx"
		/>
		<Stat label="Buckets" value={String(buckets.length)} subText="en la serie" />
	</div>

	<div class="charts">
		<Card title="Peticiones">
			{#if failed}
				<p class="failed">No se pudo leer la serie de esta instancia.</p>
			{:else}
				<EChart
					build={requestsByInstance(traffic)}
					label="Peticiones de la instancia"
					height={220}
				/>
			{/if}
		</Card>
		<Card title="Proporción de error">
			{#if failed}
				<p class="failed">No se pudo leer la serie de esta instancia.</p>
			{:else}
				<EChart build={errorRate(buckets)} label="Proporción de error" height={220} />
			{/if}
		</Card>
	</div>
</section>

<style>
	section {
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding: 12px 16px 28px;
	}

	.head {
		display: flex;
		align-items: center;
		gap: 12px;
	}

	.stats {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(172px, 1fr));
		gap: 10px;
	}

	.charts {
		display: grid;
		grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr);
		gap: 12px;
	}

	.note {
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.failed {
		margin: 0;
		padding: 60px 0;
		text-align: center;
		font-size: var(--fs-body);
		color: var(--tx3);
	}
</style>
