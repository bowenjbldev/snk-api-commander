<script lang="ts">
	import type { Snippet } from 'svelte';
	import { LegendShape } from './kinds';

	export interface LegendItem {
		label: string;
		/** El color de la muestra. Un token, resuelto por quien llama. */
		color?: string;
	}

	interface Props {
		items: LegendItem[];
		/** Frase que explica qué codifica la leyenda. */
		caption?: string;
		shape?: LegendShape;
		/** El nombre de una serie sale de los datos, así que va en mono; «añadidas», no. */
		mono?: boolean;
		/** Muestra a medida: la línea de suministro dibuja sus seis formas aquí. */
		sample?: Snippet<[LegendItem]>;
	}

	const { items, caption, shape = LegendShape.Swatch, mono = false, sample }: Props = $props();
</script>

<div class="legend" data-shape={shape}>
	{#if caption}<span class="caption">{caption}</span>{/if}
	{#each items as item (item.label)}
		<span class="item">
			{#if sample}
				{@render sample(item)}
			{:else if shape === LegendShape.Dash}
				<span class="dash" style:border-top-color={item.color}></span>
			{:else}
				<span class={shape} style:background={item.color}></span>
			{/if}
			<span class="label" class:mono>{item.label}</span>
		</span>
	{/each}
</div>

<style>
	.legend {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 4px 14px;
	}

	/* La leyenda de suministro lleva frase y siete formas: necesita más aire entre líneas. */
	.legend[data-shape='bar'] {
		gap: 7px 18px;
	}

	.caption,
	.label {
		font-size: var(--fs-small);
		white-space: nowrap;
	}

	.caption {
		color: var(--tx3);
	}

	.label {
		color: var(--tx2);
	}

	.item {
		display: flex;
		align-items: center;
		gap: 5px;
	}

	.legend[data-shape='bar'] .item {
		gap: 6px;
	}

	/* El relleno de un área: cuadrado, del tamaño de la punta de una serie. */
	.swatch {
		flex: 0 0 8px;
		width: 8px;
		height: 8px;
		border-radius: var(--r-tag);
		background: var(--tx4);
	}

	/* La línea de suministro a su tamaño, para que leyenda y fila sean la misma marca. */
	.bar {
		flex: 0 0 3px;
		width: 3px;
		height: 14px;
		border-radius: var(--r-line);
		background: var(--tx4);
	}

	/* Trazos: lo que no se midió. Una media o una proyección nunca se dibuja continua. */
	.dash {
		flex: 0 0 11px;
		width: 11px;
		height: 0;
		border-top: 1.2px dashed var(--tx3);
	}
</style>
