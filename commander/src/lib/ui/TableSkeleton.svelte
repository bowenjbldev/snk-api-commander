<script lang="ts">
	interface Props {
		/** Cuántas filas fingir. Del alto exacto de la fila real, para que no salte al llegar. */
		rows?: number;
		/** Anchos de las barras por columna, en porcentaje. Uno por celda visible. */
		widths?: string[];
		dense?: boolean;
	}

	const {
		rows = 8,
		widths = ['70%', '55%', '80%', '45%', '60%', '50%'],
		dense = false
	}: Props = $props();
</script>

{#each Array.from({ length: rows }, (_, i) => i) as row (row)}
	<div class="row" class:dense aria-hidden="true">
		{#each widths as width, cell (cell)}
			<span class="bar" style:width></span>
		{/each}
	</div>
{/each}

<style>
	.row {
		display: grid;
		grid-template-columns: var(--cols);
		align-items: center;
		height: var(--h-row);
		border-bottom: 1px solid var(--bd2);
	}

	.row.dense {
		height: var(--h-row-dense);
	}

	.bar {
		height: 9px;
		margin: 0 8px;
		border-radius: var(--r-tag);
		background: var(--bg-fill2);
	}
</style>
