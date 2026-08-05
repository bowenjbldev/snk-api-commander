<script lang="ts">
	import { ControlSize } from './kinds';

	export interface SegmentOption {
		value: string;
		label: string;
	}

	interface Props {
		/** Opcional: el componente arranca vacío si nadie lo ata con `bind:value`. */
		value?: string;
		options: SegmentOption[];
		label?: string;
		size?: ControlSize;
		/** Con ancho fijo los tramos se reparten a partes iguales: el par claro/oscuro. */
		width?: string;
	}

	let {
		value = $bindable(''),
		options,
		label,
		size = ControlSize.Compact,
		width
	}: Props = $props();
</script>

<div
	class="segmented"
	class:split={width !== undefined}
	role="group"
	aria-label={label}
	data-size={size}
	style:width
>
	{#each options as option (option.value)}
		<button
			type="button"
			aria-pressed={value === option.value}
			onclick={() => (value = option.value)}
		>
			{option.label}
		</button>
	{/each}
</div>

<style>
	/* Una sola caja con divisorias dentro, no una fila de botones sueltos: el radio lo pone el
	   contenedor y `overflow` recorta las esquinas de los extremos. */
	.segmented {
		display: inline-flex;
		border: 1px solid var(--bd);
		border-radius: var(--r);
		background: var(--bg-card);
		overflow: hidden;
	}

	button {
		flex: 0 0 auto;
		height: var(--h-ctl);
		padding: 0 10px;
		border: 0;
		background: transparent;
		color: var(--tx2);
		font-size: var(--fs-body);
		white-space: nowrap;
	}

	/* Dentro de un cajón el control cede dos píxeles al formulario, que manda. No baja hasta
	   --h-ctl-dense (24): a 24 el rótulo de un tramo se pega al borde. */
	[data-size='dense'] button {
		height: 26px;
		padding: 0 12px;
		font-size: var(--fs-small);
	}

	.split button {
		flex: 1;
	}

	button:not(:last-child) {
		border-right: 1px solid var(--bd2);
	}

	/* El activo es una selección, no una acción: lleva el primario en texto y fondo tenue, sin
	   píldora elevada y sin sombra. Los inactivos no se iluminan al apuntar —el grupo entero es un
	   solo mando, y encender el que no está puesto miente sobre dónde está el valor. */
	button[aria-pressed='true'] {
		background: var(--pri-bg);
		color: var(--pri);
		font-weight: var(--fw-bold);
	}
</style>
