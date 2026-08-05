<script lang="ts">
	import type { Snippet } from 'svelte';
	import { SortState } from './kinds';

	interface Props {
		/** Sin rótulo para las columnas de mando, que no anuncian nada. */
		children?: Snippet;
		/** Con `onsort` la cabecera es pulsable; sin él es un rótulo y nada más. */
		onsort?: () => void;
		sort?: SortState;
		align?: 'left' | 'right';
		title?: string;
	}

	const { children, onsort, sort = SortState.None, align = 'left', title }: Props = $props();

	const ARROW: Record<SortState, string> = {
		[SortState.None]: '',
		[SortState.Ascending]: '↑',
		[SortState.Descending]: '↓'
	};
</script>

<!-- `aria-sort` vive en la celda de cabecera, no en el botón: es la columna la que está
	 ordenada, y el botón solo es el mando que la reordena. -->
<div class="th" role="columnheader" aria-sort={onsort ? sort : undefined} data-align={align}>
	{#if onsort && children}
		<!-- La columna que manda no necesita más señal que su propia flecha: el resalte y la
			 flecha aparecen a la vez, así que no hay dos formas de decir lo mismo. -->
		<button type="button" onclick={onsort} {title} class:active={sort !== SortState.None}>
			{@render children()}
			{ARROW[sort]}
		</button>
	{:else if children}
		<span class="plain" {title}>{@render children()}</span>
	{/if}
</div>

<style>
	.th {
		display: flex;
		align-items: center;
		min-width: 0;
		padding: 0 8px;
	}

	.th[data-align='right'] {
		justify-content: flex-end;
		padding: 0 6px;
	}

	button,
	.plain {
		padding: 0;
		border: 0;
		background: transparent;
		font-size: var(--fs-small);
		color: var(--tx2);
		text-transform: uppercase;
		letter-spacing: var(--ls-caps);
		white-space: nowrap;
	}

	/* Sube a texto principal, no al primario: el orden es una lectura de la tabla, no un mando
	   encendido. El primario aquí competiría con la fila seleccionada. */
	button.active {
		color: var(--tx);
	}
</style>
