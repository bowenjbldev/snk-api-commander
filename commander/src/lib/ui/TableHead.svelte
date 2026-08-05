<script lang="ts">
	import type { Snippet } from 'svelte';

	/** Compacta: la cabecera baja a 28 con las filas, o el caudal de registros descuadra. */
	const { children, dense = false }: { children: Snippet; dense?: boolean } = $props();
</script>

<div class="head" class:dense role="row">{@render children()}</div>

<style>
	.head {
		display: grid;
		grid-template-columns: var(--cols);
		align-items: center;
		height: var(--h-row);
		background: var(--bg-fill);
		border-bottom: 1px solid var(--bd);
		font-size: var(--fs-small);
		color: var(--tx2);
		text-transform: uppercase;
		letter-spacing: var(--ls-caps);
	}

	.head.dense {
		height: var(--h-row-dense);
	}
</style>
