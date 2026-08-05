<script lang="ts">
	import { Tone } from '$lib/model/enums';
	import { session } from '$lib/state/session.svelte';
	import Button from '$lib/ui/Button.svelte';
	import { ControlSize } from '$lib/ui/kinds';
	import Toolbar from '$lib/ui/Toolbar.svelte';

	interface Props {
		count: number;
		onclear: () => void;
	}

	const { count, onclear }: Props = $props();

	// La consola oculta lo que no procede, pero la API vuelve a comprobarlo: «sin permiso» es un
	// estado de componente más, no una frontera.
	const canControl = $derived(session.can('control packages'));
	const word = $derived(count === 1 ? 'seleccionada' : 'seleccionadas');
</script>

<Toolbar selected>
	<span class="count"><span class="mono">{count}</span> {word}</span>
	<span class="sep">│</span>
	{#if canControl}
		<!-- Cada mando lleva su medida dentro: el preaviso es ámbar, el corte rojo y restablecer
		     verde. Es la misma escala que la línea de suministro de la fila, y por eso se lee sin
		     tener que abrir nada. -->
		<Button size={ControlSize.Bar} tone={Tone.Warn}>Iniciar preaviso</Button>
		<Button size={ControlSize.Bar} tone={Tone.Ok}>Restablecer suministro</Button>
		<Button size={ControlSize.Bar} tone={Tone.Error}>Cortar suministro</Button>
	{:else}
		<span class="denied">Sin permiso para controlar productos.</span>
	{/if}
	{#snippet trailing()}
		<Button size={ControlSize.Bar} onclick={onclear}>Limpiar selección</Button>
	{/snippet}
</Toolbar>

<style>
	.count {
		font-size: var(--fs-body);
		white-space: nowrap;
	}

	.sep {
		color: var(--bd);
	}

	.denied {
		font-size: var(--fs-body);
		color: var(--tx3);
	}
</style>
