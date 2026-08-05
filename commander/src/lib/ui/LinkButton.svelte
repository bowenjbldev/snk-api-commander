<script lang="ts">
	import type { Snippet } from 'svelte';

	interface Props {
		children: Snippet;
		onclick?: (event: MouseEvent) => void;
		disabled?: boolean;
	}

	const { children, onclick, disabled = false }: Props = $props();
</script>

<button type="button" {disabled} {onclick}>{@render children()}</button>

<style>
	/* Una salida lateral al pie de un campo —dar de alta el cliente que falta en el desplegable—:
	   no es una acción del formulario, así que no puede tener caja ni competir por el sitio. */
	button {
		display: block;
		padding: 0;
		border: 0;
		background: transparent;
		color: var(--pri);
		font-size: var(--fs-small);
		line-height: 14px;
		text-align: left;
	}

	button:hover:not(:disabled) {
		text-decoration: underline;
	}

	button:disabled {
		opacity: 0.45;
		cursor: not-allowed;
	}
</style>
