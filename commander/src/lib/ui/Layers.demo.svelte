<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Layers,
		name: 'Cajón · Menú · Avisos',
		notes:
			'Lo único que lleva sombra es lo que flota. Escape cierra, el foco queda dentro y vuelve al salir. El menú se ancla a las coordenadas del clic; el aviso se apila abajo a la derecha.',
		components: ['Drawer', 'Menu', 'Toaster']
	};
</script>

<script lang="ts">
	import { Tone } from '$lib/model/enums';
	import { toasts } from '$lib/state/toasts.svelte';
	import Button from './Button.svelte';
	import Drawer from './Drawer.svelte';
	import Field from './Field.svelte';
	import Menu from './Menu.svelte';
	import Segmented from './Segmented.svelte';
	import TextInput from './TextInput.svelte';
	import Toaster from './Toaster.svelte';
	import { ButtonVariant, ControlSize } from './kinds';

	const TONES = [Tone.Ok, Tone.Warn, Tone.Error, Tone.Info, Tone.Neutral];
	const MODES = [
		{ value: 'existing', label: 'Tarifa existente' },
		{ value: 'new', label: 'Tarifa nueva' }
	];

	// El ítem imposible se queda en la lista, apagado: enseña que el mando existe y por qué no toca.
	const noop = (): void => {};
	const ITEMS = [
		{ label: 'Abrir instancia', run: noop },
		{ label: 'Cambiar de tarifa', run: noop },
		{ label: 'Reintentar el encolado', run: noop, tone: Tone.Info },
		{ label: 'Restablecer el suministro', run: noop, tone: Tone.Ok },
		{ label: 'Iniciar preaviso', run: noop, tone: Tone.Warn },
		{ label: 'Cortar el suministro', run: noop, tone: Tone.Error },
		{ label: 'Sin tarifa: no se puede provisionar', run: noop, disabled: true }
	];

	let drawer = $state(false);
	let menu = $state<{ x: number; y: number } | null>(null);
	let company = $state('Kilkana Media');
	let slug = $state('kilkana');
	let mode = $state('existing');
</script>

<div class="row">
	<Button
		size={ControlSize.Compact}
		variant={ButtonVariant.Primary}
		onclick={() => (drawer = true)}
	>
		Dar de alta cliente
	</Button>
	<Button
		size={ControlSize.Compact}
		onclick={(event: MouseEvent) => {
			menu = { x: event.clientX, y: event.clientY };
		}}
	>
		Menú de fila ⋯
	</Button>
	{#each TONES as tone (tone)}
		<Button
			size={ControlSize.Compact}
			onclick={() => {
				toasts.push(`Aviso de tono ${tone}`, tone);
			}}
		>
			Aviso {tone}
		</Button>
	{/each}
</div>

{#if drawer}
	<Drawer
		title="Dar de alta cliente"
		confirmLabel="Crear cliente"
		onconfirm={() => (drawer = false)}
		onclose={() => (drawer = false)}
	>
		<Field label="Empresa">
			{#snippet children(id)}<TextInput {id} bind:value={company} width="100%" />{/snippet}
		</Field>
		<Field label="Subdominio" hint="{slug}.snakepc.test">
			{#snippet children(id)}<TextInput {id} bind:value={slug} mono width="100%" />{/snippet}
		</Field>
		<Field label="Tarifa" separated>
			<Segmented bind:value={mode} options={MODES} label="Tarifa" />
		</Field>
	</Drawer>
{/if}

{#if menu}
	<Menu x={menu.x} y={menu.y} onclose={() => (menu = null)} items={ITEMS} />
{/if}

<Toaster items={toasts.items} />

<style>
	.row {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
	}
</style>
