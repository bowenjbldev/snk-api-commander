<script lang="ts">
	import type { Snippet } from 'svelte';

	interface Props {
		label: string;
		/** El valor sale del sistema casi siempre, así que por defecto va en mono. */
		value?: string;
		mono?: boolean;
		/** Cuando el valor no es texto: un enlace, una etiqueta, una cuenta atrás. */
		children?: Snippet;
	}

	const { label, value, mono = true, children }: Props = $props();
</script>

<div class="row">
	<span class="key">{label}</span>
	{#if children}
		{@render children()}
	{:else}
		<span class="value" class:mono>{value}</span>
	{/if}
</div>

<style>
	/* Columna de rótulo fija: el ojo baja por los valores sin buscar dónde empiezan. */
	.row {
		display: grid;
		grid-template-columns: 120px minmax(0, 1fr);
		gap: 12px;
		padding: 6px 0;
		border-bottom: 1px solid var(--bd2);
	}

	.key {
		font-size: var(--fs-small);
		color: var(--tx2);
		text-transform: uppercase;
		letter-spacing: var(--ls-caps);
	}

	/* Una ruta o un hash largos parten donde sea antes que ensanchar la tarjeta. */
	.value {
		font-size: var(--fs-body);
		color: var(--tx);
		word-break: break-all;
	}
</style>
