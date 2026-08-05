<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Fields,
		name: 'Switch',
		notes:
			'Preferencias que se aplican al momento; no hay botón de guardar detrás. La perilla apagada es gris, no blanca: el riel no está encendido.'
	};
</script>

<script lang="ts">
	import Switch from './Switch.svelte';

	let cut = $state(true);
	let overdue = $state(false);
</script>

<div class="stack">
	<Switch
		bind:checked={cut}
		label="Corte automático"
		note="La lectura de contadores puede cortar sin que toques nada"
	/>
	<Switch bind:checked={overdue} label="Factura vencida" note="Enlaza el cobro con el suministro" />
	<Switch checked={false} label="Sin nota" />
	<Switch checked disabled label="Controlar productos" note="Sin permiso: lo decide la API" />
	<Switch checked={false} disabled label="Provisionar productos" note="Sin permiso, y apagado" />
</div>

<style>
	.stack {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}
</style>
