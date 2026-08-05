<script lang="ts">
	interface Props {
		/** El dato que se copia y que además se muestra: subdominio, identificador. */
		value: string;
		/** Para que la pantalla avise; la primitiva no monta su propio aviso. */
		oncopied?: (value: string) => void;
	}

	const { value, oncopied }: Props = $props();

	async function copy(): Promise<void> {
		try {
			await navigator.clipboard.writeText(value);
			oncopied?.(value);
		} catch {
			// Sin permiso de portapapeles no hay nada que fingir ni que avisar: el dato sigue a la
			// vista y se puede seleccionar a mano, que es justo para lo que va en mono.
		}
	}
</script>

<button class="mono" type="button" title="Copiar" onclick={copy}>
	<span class="text">{value}</span>
	<svg
		width="11"
		height="11"
		viewBox="0 0 16 16"
		fill="none"
		stroke="currentColor"
		stroke-width="1.4"
		aria-hidden="true"
	>
		<rect x="5.5" y="5.5" width="8" height="8" rx="1.5" />
		<path d="M10.5 3.5V3a1.5 1.5 0 0 0-1.5-1.5H3A1.5 1.5 0 0 0 1.5 3v6A1.5 1.5 0 0 0 3 10.5h.5" />
	</svg>
</button>

<style>
	/* El dato ES el botón: no hay icono suelto que buscar, se apunta al propio subdominio. */
	button {
		display: flex;
		align-items: center;
		gap: 5px;
		flex: 0 1 auto;
		min-width: 0;
		padding: 0;
		border: 0;
		background: transparent;
		color: var(--tx3);
		font-size: var(--fs-small);
		white-space: nowrap;
	}

	button:hover {
		color: var(--pri);
	}

	.text {
		min-width: 0;
		overflow: hidden;
		text-overflow: ellipsis;
	}
</style>
