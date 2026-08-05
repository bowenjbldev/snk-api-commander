<script lang="ts">
	import { usage } from '$lib/api/resources';
	import { attentionItems, type AttentionItem } from '$lib/domain/attention';
	import AttentionTray from '$lib/domain/AttentionTray.svelte';
	import { k } from '$lib/format';
	import { PackageStatus, Situation, TimeWindow } from '$lib/model/enums';
	import { displayName, situationOf } from '$lib/model/situation';
	import type { CommanderUsageBucket } from '$lib/model/types';
	import { navigate, packageRoute } from '$lib/nav/routes';
	import PageHeader from '$lib/shell/PageHeader.svelte';
	import { clock } from '$lib/state/clock.svelte';
	import { data } from '$lib/state/data.svelte';
	import Card from '$lib/ui/Card.svelte';
	import Legend from '$lib/ui/Legend.svelte';
	import Segmented from '$lib/ui/Segmented.svelte';
	import EChart from '$lib/viz/EChart.svelte';
	import OverviewStats from './OverviewStats.svelte';
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
	let trafficError = $state(false);

	// Cada tarjeta resuelve lo suyo: si la serie no llega, la gráfica lo dice y el resto de la
	// pantalla sigue en pie. La consola no se bloquea esperando a un recurso.
	$effect(() => {
		const hours = HOURS[window] ?? 48;
		usage
			.platform(hours)
			.then((rows) => {
				buckets = rows;
				trafficError = false;
			})
			.catch(() => {
				buckets = [];
				trafficError = true;
			});
	});

	const now = $derived(clock.now);
	const situations = $derived(data.packages.map((pkg) => situationOf(pkg, now)));

	const live = $derived(situations.filter((s) => s === Situation.Live).length);
	const notice = $derived(data.packages.filter((pkg) => pkg.status === PackageStatus.Grace).length);
	const cut = $derived(
		data.packages.filter((pkg) => pkg.status === PackageStatus.Suspended).length
	);

	// No hay endpoint de MRR: se deriva de las suscripciones vigentes, como hacía la SPA vieja.
	const mrr = $derived(
		data.subscriptions
			.filter((sub) => sub.ended_at === null)
			.reduce((total, sub) => total + Number(sub.price_monthly), 0)
	);

	const requests = $derived(buckets.reduce((total, row) => total + row.request_count, 0));
	const errors = $derived(buckets.reduce((total, row) => total + row.error_count, 0));

	const names = $derived(new Map(data.packages.map((pkg) => [pkg.id, displayName(pkg)])));
	const traffic = $derived(toTraffic(buckets, (id) => names.get(id) ?? `#${id}`));

	const attention = $derived(
		attentionItems({ packages: data.packages, invoices: data.invoices, now })
	);
</script>

<svelte:head><title>Commander · Panorama</title></svelte:head>

<PageHeader title="Panorama">
	{#snippet action()}
		<Segmented bind:value={window} options={WINDOWS} label="Ventana de tiempo" />
	{/snippet}
</PageHeader>

<section>
	<OverviewStats {live} {notice} {cut} {requests} {errors} {mrr} />

	<div class="charts">
		<Card title="Peticiones por instancia" note={k(requests)}>
			{#snippet aside()}
				<Legend
					items={traffic.series.map((serie, i) => ({ label: serie.name, color: seriesCssVar(i) }))}
				/>
			{/snippet}
			{#if trafficError}
				<p class="failed">No se pudo leer la serie de tráfico.</p>
			{:else}
				<EChart build={requestsByInstance(traffic)} label="Peticiones por instancia" />
			{/if}
		</Card>

		<Card title="Proporción de error">
			{#if trafficError}
				<p class="failed">No se pudo leer la serie de tráfico.</p>
			{:else}
				<EChart build={errorRate(buckets)} label="Proporción de error 4xx y 5xx" height={180} />
			{/if}
		</Card>
	</div>

	<Card flush title="Bandeja de atención" note="{attention.length} pendientes">
		{#snippet aside()}
			<span class="hint">Cada línea exige una decisión</span>
		{/snippet}
		<AttentionTray
			items={attention}
			onrun={(item: AttentionItem) => {
				if (item.packageId !== undefined) navigate(packageRoute(item.packageId));
			}}
		/>
	</Card>
</section>

<style>
	section {
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding: 14px 16px 28px;
	}

	.charts {
		display: grid;
		grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr);
		gap: 12px;
	}

	.hint {
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.failed {
		margin: 0;
		padding: 48px 0;
		text-align: center;
		font-size: var(--fs-body);
		color: var(--tx3);
	}
</style>
