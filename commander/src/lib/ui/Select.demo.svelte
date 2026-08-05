<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Fields,
		name: 'Select',
		notes:
			'El rótulo del filtro va dentro de la opción: «Estado: todos», no una etiqueta aparte. En mono cuando la opción ES el dato.'
	};
</script>

<script lang="ts">
	import { OnExceed } from '$lib/model/enums';
	import { ControlSize } from './kinds';
	import Select from './Select.svelte';

	let status = $state('all');
	let policy = $state<string>(OnExceed.Suspend);
	let size = $state('25');

	const STATUS = [
		{ value: 'all', label: 'Estado: todos' },
		{ value: 'active', label: 'Activos' },
		{ value: 'inactive', label: 'Inactivos' }
	];

	const POLICY = [
		{ value: OnExceed.None, label: 'none' },
		{ value: OnExceed.Warn, label: 'warn' },
		{ value: OnExceed.Suspend, label: 'suspend' }
	];

	const SIZES = ['10', '25', '50', '100'].map((n) => ({ value: n, label: n }));
</script>

<div class="grid">
	<span>28 · filtro de barra</span>
	<Select bind:value={status} options={STATUS} />

	<span>28 · mono, la opción es el dato</span>
	<Select bind:value={policy} options={POLICY} mono title="Qué pasa al exceder el límite" />

	<span>24 · mono, paginador</span>
	<Select bind:value={size} options={SIZES} mono size={ControlSize.Dense} />

	<span>32 · cabecera</span>
	<Select value="all" options={STATUS} size={ControlSize.Regular} />

	<span>ancho fijo dentro de un campo</span>
	<Select value="all" options={STATUS} width="100%" />

	<span>deshabilitado</span>
	<Select value="all" options={STATUS} disabled />
</div>

<style>
	.grid {
		display: grid;
		grid-template-columns: max-content 240px;
		align-items: center;
		justify-items: start;
		gap: 8px 14px;
	}

	span {
		font-size: var(--fs-small);
		color: var(--tx3);
		text-align: right;
		justify-self: end;
	}
</style>
