<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Domain,
		name: 'Meter',
		notes:
			'Una cuota no es un porcentaje: es una lectura con un límite y un punto donde salta el limitador. Acercarse al techo importa más que haberlo pasado.'
	};
</script>

<script lang="ts">
	import { MetricKey, OnExceed } from '$lib/model/enums';
	import { meter } from './meter';
	import Meter from './Meter.svelte';

	const CASES = [
		{
			key: MetricKey.RequestsDay,
			current: 12_400,
			limit: 100_000,
			onExceed: OnExceed.Suspend,
			note: 'por debajo del umbral: estar bien no es una señal'
		},
		{
			key: MetricKey.DiskDbMb,
			current: 16_800,
			limit: 20_480,
			onExceed: OnExceed.Warn,
			note: 'en el umbral del 80 %'
		},
		{
			key: MetricKey.RequestsDay,
			current: 103_000,
			limit: 100_000,
			onExceed: OnExceed.Suspend,
			note: 'el exceso sale del carril'
		},
		{
			key: MetricKey.UsersTotal,
			current: 41,
			limit: null,
			onExceed: OnExceed.None,
			note: 'límite vacío = ilimitado: carril tenue, sin umbral'
		},
		{
			key: MetricKey.DiskStorageMb,
			current: 51_100,
			limit: 51_200,
			onExceed: OnExceed.None,
			note: 'al borde, pero la política solo lo registra'
		}
	];
</script>

<div class="rows">
	{#each CASES as item, i (i)}
		<div class="row">
			<span class="mono key">{item.key}</span>
			<Meter meter={meter(item)} />
			<span class="note">{item.note}</span>
		</div>
	{/each}
</div>

<p class="foot">
	La marca del carril es el umbral de aviso al 80 %. La barra roja al final marca la política
	<span class="mono">suspend</span>: un contador que puede cortar no significa lo mismo que uno que
	solo registra.
</p>

<style>
	.rows {
		display: flex;
		flex-direction: column;
		gap: 10px;
	}

	.row {
		display: flex;
		align-items: center;
		gap: 12px;
	}

	.key {
		width: 140px;
		flex: 0 0 140px;
		font-size: var(--fs-small);
		color: var(--tx2);
	}

	.row :global(.meter) {
		max-width: 220px;
	}

	.note {
		flex: 1;
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.foot {
		margin: 14px 0 0;
		font-size: var(--fs-small);
		color: var(--tx2);
	}
</style>
