<script lang="ts">
	import type { Snippet } from 'svelte';

	interface Props {
		label: string;
		children: Snippet;
		onclick?: (event: MouseEvent) => void;
		/** 24 dentro de una fila, 32 en la cabecera de la aplicación. */
		size?: 24 | 32;
		/** Con caja: el «⋯» que acompaña a la acción primaria de una cabecera de pantalla. */
		bordered?: boolean;
		/** Al apuntar tira al rojo: cerrar sesión, descartar. */
		danger?: boolean;
		disabled?: boolean;
	}

	const {
		label,
		children,
		onclick,
		size = 24,
		bordered = false,
		danger = false,
		disabled = false
	}: Props = $props();
</script>

<button
	type="button"
	title={label}
	aria-label={label}
	{disabled}
	{onclick}
	class:danger
	class:bordered
	data-size={size}
	style:--size="{size}px"
>
	{@render children()}
</button>

<style>
	button {
		display: grid;
		place-items: center;
		width: var(--size);
		height: var(--size);
		border: 0;
		border-radius: var(--r);
		background: transparent;
		color: var(--tx3);
		font-size: var(--fs-card);
		line-height: 1;
		transition:
			background var(--t-fast) var(--ease),
			border-color var(--t-fast) var(--ease);
	}

	/* Dentro de una fila el fondo de apuntado de la fila ya está puesto, así que el botón necesita
	   un escalón más para verse; en la cabecera no hay nada debajo y basta el suave. */
	button[data-size='24']:hover:not(:disabled) {
		background: var(--bg-fill2);
		color: var(--tx);
	}

	button[data-size='32']:hover:not(:disabled) {
		background: var(--bg-hover);
		color: var(--tx);
	}

	/* Con caja no se rellena al apuntar: acompaña a un botón primario y no puede competir con él. */
	button.bordered {
		border: 1px solid var(--bd);
		background: var(--bg-card);
		color: var(--tx2);
	}

	button.bordered:hover:not(:disabled) {
		background: var(--bg-card);
		border-color: var(--pri);
		color: var(--pri);
	}

	button.danger:hover:not(:disabled) {
		color: var(--er);
	}

	button:disabled {
		opacity: 0.4;
		cursor: not-allowed;
	}
</style>
