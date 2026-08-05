<script lang="ts">
	import type { Snippet } from 'svelte';

	interface Props {
		/** Filtros y búsqueda, a la izquierda. Puede no haber ninguno. */
		children?: Snippet;
		/** Lo que va pegado a la derecha: recuento, novedades. */
		trailing?: Snippet;
		/** Barra de selección: cambia de plano para acusar que hay filas marcadas. */
		selected?: boolean;
	}

	const { children, trailing, selected = false }: Props = $props();
</script>

<div class="toolbar" class:selected>
	{#if children}{@render children()}{/if}
	<div class="spacer"></div>
	{#if trailing}{@render trailing()}{/if}
</div>

<style>
	.toolbar {
		display: flex;
		align-items: center;
		gap: 8px;
		height: var(--h-card-head);
		padding: 0 10px;
		border-bottom: 1px solid var(--bd2);
		background: var(--bg-fill);
	}

	/* Más baja y en el plano de selección: es una barra que aparece, no la de siempre. */
	.toolbar.selected {
		height: 36px;
		border-bottom-color: var(--bd);
		background: var(--bg-sel);
	}

	.spacer {
		flex: 1;
	}
</style>
