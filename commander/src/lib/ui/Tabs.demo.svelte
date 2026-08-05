<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Surfaces,
		name: 'Tabs',
		notes:
			'Las vistas de una instancia. En la consola real son rutas anidadas, no estado local, así que cada pestaña es un enlace. Cuántas hay depende de la situación: la que solo está en catálogo no tiene uso ni registros que enseñar.'
	};
</script>

<script lang="ts">
	import { PackageTab } from '$lib/nav/routes';
	import Tabs from './Tabs.svelte';

	let live = $state<string>(PackageTab.Identity);
	let catalog = $state<string>(PackageTab.Identity);

	const ALL = [
		{ value: PackageTab.Identity, label: 'Identidad' },
		{ value: PackageTab.Plans, label: 'Tarifas' },
		{ value: PackageTab.Meters, label: 'Contadores' },
		{ value: PackageTab.Usage, label: 'Uso' },
		{ value: PackageTab.Logs, label: 'Registros' },
		{ value: PackageTab.Code, label: 'Código' },
		{ value: PackageTab.Settings, label: 'Configuración' }
	];
</script>

<p class="caps">Instancia viva · siete vistas</p>
<Tabs
	tabs={ALL}
	current={live}
	onselect={(value: string) => {
		live = value;
	}}
	label="Vistas de la instancia"
/>

<p class="caps second">Solo catálogo · dos vistas</p>
<Tabs
	tabs={ALL.slice(0, 2)}
	current={catalog}
	onselect={(value: string) => {
		catalog = value;
	}}
	label="Vistas del producto"
/>

<style>
	.caps {
		margin: 0 0 8px;
	}

	.second {
		margin-top: 24px;
	}
</style>
