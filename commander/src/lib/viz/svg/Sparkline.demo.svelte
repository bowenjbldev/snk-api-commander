<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Charts,
		name: 'Sparkline',
		notes:
			'SVG a mano y no una gráfica por fila: con cien mil filas, una instancia de librería por fila es inaceptable.'
	};
</script>

<script lang="ts">
	import { CssVar } from '$lib/tokens/tokens';
	import Sparkline from './Sparkline.svelte';

	// Serie determinista: la guía tiene que verse igual en cada recarga.
	const wave = (n: number, seed: number) =>
		Array.from({ length: n }, (_, i) => Math.abs(Math.sin(i / 2.3 + seed)) * 100 + i * seed);

	const ROWS = [
		{ name: 'atlas-crm', points: wave(24, 1), color: `var(${CssVar.Text3})` },
		{ name: 'beacon-api', points: wave(24, 2.4), color: `var(${CssVar.Warn})` },
		{
			name: 'faro-cms',
			points: Array.from({ length: 24 }, () => 0),
			color: `var(${CssVar.Text4})`
		},
		{ name: 'cauce-etl', points: wave(24, 0.4), color: `var(${CssVar.Ok})` }
	];
</script>

<div class="rows">
	{#each ROWS as row (row.name)}
		<div class="row">
			<span class="mono name">{row.name}</span>
			<Sparkline points={row.points} color={row.color} label="Tráfico de {row.name}" />
		</div>
	{/each}
</div>

<style>
	.rows {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	.row {
		display: flex;
		align-items: center;
		gap: 10px;
	}

	.name {
		width: 120px;
		font-size: var(--fs-body);
		color: var(--tx2);
	}
</style>
