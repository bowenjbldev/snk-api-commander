<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Fields,
		name: 'Segmented',
		notes:
			'Pocas opciones, todas visibles, una activa. Los inactivos no se iluminan al apuntar: el grupo entero es un solo mando.'
	};
</script>

<script lang="ts">
	import { TimeWindow, UsagePeriod } from '$lib/model/enums';
	import { ControlSize } from './kinds';
	import Segmented from './Segmented.svelte';

	let window = $state<string>(TimeWindow.Hours48);
	let period = $state<string>(UsagePeriod.Hour);
	let mode = $state('new');
	let theme = $state('light');

	// Rótulos tal cual los del prototipo: la unidad pegada a la cifra va entera en mono («48 h»),
	// el resto son palabra nuestra y van capitalizadas.
	const WINDOWS = [
		{ value: TimeWindow.Hours48, label: '48 h' },
		{ value: TimeWindow.Week, label: 'Semana' },
		{ value: TimeWindow.Month, label: 'Mes' },
		{ value: TimeWindow.Quarter, label: 'Trimestre' },
		{ value: TimeWindow.Year, label: 'Año' }
	];

	const PERIODS = [
		{ value: UsagePeriod.Hour, label: 'Por hora' },
		{ value: UsagePeriod.Day, label: 'Por día' }
	];

	const MODES = [
		{ value: 'new', label: 'Tarifa nueva' },
		{ value: 'existing', label: 'Ya existente' }
	];

	const THEMES = [
		{ value: 'light', label: 'Claro' },
		{ value: 'dark', label: 'Oscuro' }
	];
</script>

<div class="grid">
	<span>28 · cinco opciones</span>
	<Segmented bind:value={window} options={WINDOWS} label="Ventana de tiempo" />

	<span>28 · dos opciones</span>
	<Segmented bind:value={period} options={PERIODS} label="Granularidad" />

	<span>26 · dentro de un cajón</span>
	<Segmented bind:value={mode} options={MODES} size={ControlSize.Dense} label="Tarifa" />

	<span>ancho fijo, tramos iguales</span>
	<Segmented bind:value={theme} options={THEMES} width="180px" label="Tema" />
</div>

<style>
	.grid {
		display: grid;
		grid-template-columns: max-content max-content;
		align-items: center;
		gap: 10px 14px;
	}

	span {
		font-size: var(--fs-small);
		color: var(--tx3);
		text-align: right;
	}
</style>
