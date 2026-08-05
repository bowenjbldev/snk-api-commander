<script lang="ts">
	import type { Snippet } from 'svelte';

	interface Props {
		children: Snippet;
		/** Fila compacta: registros y caudal. */
		dense?: boolean;
		onclick?: () => void;
		/**
		 * Un empuje del servidor que corrige una mutación optimista resalta la fila una vez. No se
		 * repite: es un acuse, no un estado.
		 */
		flash?: boolean;
	}

	const { children, dense = false, onclick, flash = false }: Props = $props();
</script>

<div
	class="row"
	class:dense
	class:flash
	class:clickable={onclick !== undefined}
	role="row"
	{onclick}
	onkeydown={(event) => {
		if (onclick && (event.key === 'Enter' || event.key === ' ')) {
			event.preventDefault();
			onclick();
		}
	}}
	tabindex={onclick ? 0 : undefined}
>
	{@render children()}
</div>

<style>
	.row {
		display: grid;
		grid-template-columns: var(--cols);
		align-items: center;
		min-height: var(--h-row);
		border-bottom: 1px solid var(--bd2);
	}

	.row.dense {
		min-height: var(--h-row-dense);
	}

	.row.clickable {
		cursor: pointer;
	}

	.row:hover {
		background: var(--bg-hover);
	}

	/* Seleccionar NO tiñe la fila: la marca es la casilla, que se llena de primario. Teñir además
	   el fondo gastaría el mismo recurso dos veces y taparía el destello de una corrección. */
	.row.flash {
		animation: flash 1.2s ease-out;
	}
</style>
