<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Data,
		name: 'Legend',
		notes:
			'Tres muestras: cuadrado para un área, barra de 3 px para el suministro, trazos para lo que no se midió.'
	};
</script>

<script lang="ts">
	import { CssVar } from '$lib/tokens/tokens';
	import Legend from './Legend.svelte';
	import { LegendShape } from './kinds';

	const SERIES = [
		{ label: 'atlas-crm', color: `var(${CssVar.Series1})` },
		{ label: 'beacon-api', color: `var(${CssVar.Series2})` },
		{ label: 'faro-cms', color: `var(${CssVar.Series3})` },
		{ label: 'cauce-etl', color: `var(${CssVar.Series4})` },
		{ label: 'resto de la flota', color: `var(${CssVar.Series5})` }
	];

	const STATES = [
		{ label: '4xx', color: `var(${CssVar.Warn})` },
		{ label: '5xx', color: `var(${CssVar.Error})` }
	];

	const CODE = [
		{ label: 'añadidas', color: `var(${CssVar.Series3})` },
		{ label: 'borradas', color: `var(${CssVar.Series5})` }
	];

	const SUPPLY = [
		{ label: 'Conectada', color: `var(${CssVar.Ok})` },
		{ label: 'Preaviso corriendo', color: `var(${CssVar.Warn})` },
		{ label: 'Cortada', color: `var(${CssVar.Error})` },
		{ label: 'Alta en curso', color: `var(${CssVar.Info})` },
		{ label: 'Sin suministro', color: `var(${CssVar.Border})` }
	];

	const MEAN = [{ label: 'media de la ventana' }, { label: 'neto por semana' }];
</script>

<div class="stack">
	<Legend items={SERIES} mono />
	<Legend items={STATES} caption="Cuando la serie es un estado usa el semántico, no la paleta:" />
	<Legend items={CODE} />
	<Legend
		items={SUPPLY}
		shape={LegendShape.Bar}
		caption="La línea de la izquierda codifica el suministro por color y por forma:"
	/>
	<Legend items={MEAN} shape={LegendShape.Dash} />
</div>

<p class="note">
	El nombre de una serie sale de los datos y va en mono; «añadidas» es palabra nuestra y no. La
	leyenda de suministro de verdad pasa la propia línea por <code class="mono">sample</code>, que es
	quien sabe dibujar sus seis formas.
</p>

<style>
	.stack {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}

	.note {
		margin: 12px 0 0;
		font-size: var(--fs-small);
		color: var(--tx2);
	}

	code {
		font-size: var(--fs-small);
		color: var(--tx3);
	}
</style>
