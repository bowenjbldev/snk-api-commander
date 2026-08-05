<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Fields,
		name: 'Checkbox',
		notes:
			'Solo vive en la columna de selección de una tabla: 12 px, apagada transparente para no recortarse del fondo de la fila.'
	};
</script>

<script lang="ts">
	import Checkbox from './Checkbox.svelte';

	let one = $state(true);
	let two = $state(false);
</script>

<div class="grid">
	<span>marcada</span>
	<Checkbox bind:checked={one} label="Seleccionar Atlas CRM" />

	<span>vacía</span>
	<Checkbox bind:checked={two} label="Seleccionar The Beacon" />

	<span>parcial · cabecera</span>
	<Checkbox mixed label="Seleccionar toda la página" title="Alguna sí y alguna no" />

	<span>sin selección posible</span>
	<Checkbox disabled label="Sin suministro" title="Sin suministro: no admite acciones" />

	<span>sobre fila seleccionada</span>
	<div class="row"><Checkbox checked label="Seleccionar Tinta Render" /></div>
</div>

<style>
	.grid {
		display: grid;
		grid-template-columns: max-content max-content;
		align-items: center;
		gap: 2px 14px;
	}

	span {
		font-size: var(--fs-small);
		color: var(--tx3);
		text-align: right;
	}

	/* La casilla apagada es transparente justo para esto: se lee igual sobre el fondo de una fila
	   seleccionada que sobre la tarjeta. */
	.row {
		padding: 0 10px;
		background: var(--bg-sel);
	}
</style>
