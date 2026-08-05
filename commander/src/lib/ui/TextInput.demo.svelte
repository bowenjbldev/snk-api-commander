<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Fields,
		name: 'TextInput',
		notes:
			'Lo que se escribe contra un dato del sistema —slug, id, ruta— va en mono. El foco es el aro de la consola: pincha cualquiera para verlo.'
	};
</script>

<script lang="ts">
	import { ControlSize } from './kinds';
	import TextInput from './TextInput.svelte';

	let query = $state('atlas-crm');
	let label = $state('Atlas CRM');
	let empty = $state('');
	let limit = $state('');
	let big = $state('The Beacon');
</script>

<div class="grid">
	<span>mono, con valor</span>
	<TextInput bind:value={query} mono width="280px" />

	<span>mono, vacío con marcador</span>
	<TextInput bind:value={empty} mono width="280px" placeholder="Buscar producto o cliente" />

	<span>texto normal</span>
	<TextInput bind:value={label} width="280px" />

	<span>32 · cabecera</span>
	<TextInput bind:value={big} size={ControlSize.Regular} width="280px" />

	<span>28 · por defecto</span>
	<TextInput value="requests_day" mono width="280px" />

	<span>24 · dentro de una fila</span>
	<TextInput
		bind:value={limit}
		placeholder="ilimitado"
		mono
		size={ControlSize.Dense}
		width="120px"
	/>

	<span>error</span>
	<TextInput value="the beacon" mono invalid width="280px" />

	<span>solo lectura</span>
	<TextInput value="demo_kilkana" mono readonly width="280px" />

	<span>deshabilitado</span>
	<TextInput value="No se puede tocar" mono disabled width="280px" />

	<span>contraseña</span>
	<TextInput value="secreto-de-prueba" type="password" mono width="280px" />
</div>

<style>
	.grid {
		display: grid;
		grid-template-columns: max-content max-content;
		align-items: center;
		gap: 8px 14px;
	}

	span {
		font-size: var(--fs-small);
		color: var(--tx3);
		text-align: right;
	}
</style>
