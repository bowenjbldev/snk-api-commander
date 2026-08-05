<script lang="ts">
	import type { Snippet } from 'svelte';

	interface Props {
		children: Snippet;
		/** Dato generado por el sistema: mono con cifras tabulares. */
		mono?: boolean;
		align?: 'left' | 'right';
		/** Dato secundario: 11 en vez de 12. */
		small?: boolean;
		muted?: boolean;
		/** Corta con puntos suspensivos en vez de desbordar la rejilla. */
		truncate?: boolean;
		title?: string;
	}

	const {
		children,
		mono = false,
		align = 'left',
		small = false,
		muted = false,
		truncate = false,
		title
	}: Props = $props();
</script>

<div
	class="cell"
	class:mono
	class:small
	class:muted
	class:truncate
	data-align={align}
	role="cell"
	{title}
>
	{@render children()}
</div>

<style>
	.cell {
		display: flex;
		align-items: center;
		gap: 6px;
		min-width: 0;
		padding: 0 8px;
		font-size: var(--fs-body);
		color: var(--tx);
	}

	/* `text-align` además del `justify-content` porque una celda truncada es un bloque, no una
	   caja flexible, y ahí el reparto del eje no la alinea. */
	.cell[data-align='right'] {
		justify-content: flex-end;
		padding: 0 6px;
		text-align: right;
	}

	.small {
		font-size: var(--fs-small);
	}

	.muted {
		color: var(--tx2);
	}

	.truncate {
		display: block;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}
</style>
