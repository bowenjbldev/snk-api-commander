<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Fields,
		name: 'Field',
		notes:
			'El error dice qué pasó y qué hacer, y no borra la pista: son dos cosas distintas. El id lo pone el campo, no quien lo usa.'
	};
</script>

<script lang="ts">
	import { ControlSize } from './kinds';
	import Field from './Field.svelte';
	import LinkButton from './LinkButton.svelte';
	import Segmented from './Segmented.svelte';
	import Select from './Select.svelte';
	import TextInput from './TextInput.svelte';

	let slug = $state('tinta-render');
	let plan = $state('Pro');
	let client = $state('atlas');
	let mode = $state('new');

	const CLIENTS = [
		{ value: 'atlas', label: 'Atlas CRM' },
		{ value: 'beacon', label: 'The Beacon' }
	];

	const MODES = [
		{ value: 'new', label: 'Tarifa nueva' },
		{ value: 'existing', label: 'Ya existente' }
	];
</script>

<div class="grid">
	<Field label="Slug del producto" hint="packages/{slug} · todavía no existe en disco">
		{#snippet children(id)}
			<TextInput {id} bind:value={slug} mono width="100%" />
		{/snippet}
	</Field>

	<Field
		label="Nombre de la tarifa"
		error="Ya hay una tarifa «Pro» en este producto."
		hint="Se muestra en la factura del cliente."
	>
		{#snippet children(id)}
			<TextInput {id} bind:value={plan} invalid width="100%" />
		{/snippet}
	</Field>

	<Field label="Cliente">
		{#snippet children(id)}
			<Select {id} bind:value={client} options={CLIENTS} width="100%" />
		{/snippet}
		{#snippet after()}
			<LinkButton>Dar de alta un cliente</LinkButton>
		{/snippet}
	</Field>

	<Field label="Tarifa" separated hint="La línea de arriba abre grupo dentro de un formulario largo.">
		<Segmented bind:value={mode} options={MODES} size={ControlSize.Dense} label="Tarifa" />
	</Field>
</div>

<style>
	.grid {
		display: flex;
		flex-direction: column;
		gap: 12px;
		width: 320px;
	}

</style>
