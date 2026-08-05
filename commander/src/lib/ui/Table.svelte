<script lang="ts">
	import type { Snippet } from 'svelte';

	interface Props {
		/**
		 * El `grid-template-columns` de la tabla. Cabecera, filas y esqueleto lo heredan por
		 * variable, así que no pueden descuadrarse: se declara una vez y aquí.
		 */
		columns: string;
		children: Snippet;
	}

	const { columns, children }: Props = $props();
</script>

<div class="table" style:--cols={columns} role="table">
	{@render children()}
</div>

<style>
	/* Sin radio propio: las filas son un continuo y el radio lo pone la tarjeta que las contiene.
	   Sin franjas alternas: competirían con el color semántico de la fila. */
	.table {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}
</style>
