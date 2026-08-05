<script lang="ts">
	import type { Snippet } from 'svelte';
	import { Tone } from '$lib/model/enums';
	import { ButtonVariant, ControlSize } from './kinds';

	interface Props {
		variant?: ButtonVariant;
		size?: ControlSize;
		/**
		 * De qué color es, cuando el mando lleva la medida dentro: preaviso ámbar, corte rojo,
		 * restablecer verde. La variante decide relleno o tinta; el tono, qué significa.
		 */
		tone?: Tone;
		/** Menú partido: el botón hace lo suyo y el caret abre el resto de mandos. */
		caret?: boolean;
		/** Único botón de un formulario estrecho —la entrada— : ocupa el ancho. */
		full?: boolean;
		disabled?: boolean;
		type?: 'button' | 'submit';
		title?: string;
		onclick?: (event: MouseEvent) => void;
		children: Snippet;
	}

	const {
		variant = ButtonVariant.Default,
		size = ControlSize.Regular,
		tone = Tone.Neutral,
		caret = false,
		full = false,
		disabled = false,
		type = 'button',
		title,
		onclick,
		children
	}: Props = $props();
</script>

<button
	{type}
	{title}
	{disabled}
	{onclick}
	data-variant={variant}
	data-size={size}
	data-tone={tone}
	class:caret
	class:full
>
	{@render children()}
	{#if caret}
		<svg
			width="10"
			height="10"
			viewBox="0 0 10 10"
			fill="none"
			stroke="currentColor"
			stroke-width="1.6"
			stroke-linecap="round"
			aria-hidden="true"
		>
			<path d="M2 4 5 7 8 4" />
		</svg>
	{/if}
</button>

<style>
	/* Cada variante declara sus tokens una vez y el botón los usa una vez. Añadir una variante es
	   un bloque, no un estado suelto repartido por las reglas de abajo. */
	button[data-variant='primary'] {
		--bg: var(--pri);
		--fg: var(--on-pri);
		--bd-c: transparent;
		--bg-h: var(--pri-h);
		--fw: var(--fw-bold);
		--caret-op: 0.85;
		gap: 8px;
		box-shadow: var(--sh-pri);
	}
	/* Solo la secundaria tira del primario al apuntarla: no tiene color propio que defender. La
	   primaria ya lo lleva puesto, y teñirle la letra la dejaría morada sobre morada. */
	button[data-variant='default'] {
		--bg: var(--bg-card);
		--fg: var(--tx);
		--bd-c: var(--bd);
		--fg-h: var(--pri);
		--bd-h: var(--pri);
	}
	/* La novedad que se puede aplicar y el alta que falta: reclaman atención sin ser la acción de
	   la pantalla, así que llevan el primario en tinta y fondo pero no su relleno. */
	button[data-variant='accent'] {
		--bg: var(--pri-bg);
		--fg: var(--pri);
		--bd-c: var(--pri-bd);
		--fw: var(--fw-bold);
	}
	/* El tono no cambia la forma del botón, solo de qué color es. Un corte en tinta se llena de
	   rojo al apuntarlo: confirmar algo irreversible no puede gastar el mismo gesto que lo demás. */
	button[data-tone]:not([data-tone='neutral']) {
		--fg: var(--sem);
		--bd-c: var(--sem-bd);
		--bg-h: var(--sem);
		--fg-h: var(--on-pri);
		--bd-h: var(--sem);
	}
	/* Relleno: el mando que confirma lleva el color de lo que va a pasar, no el del sistema. */
	button[data-variant='primary'][data-tone]:not([data-tone='neutral']) {
		--bg: var(--sem);
		--fg: var(--on-pri);
		--bd-c: transparent;
	}
	/* La primaria de cabecera respira 2px más que la secundaria: es la única diferencia de caja
	   entre las dos y viene medida, no inventada. */
	button[data-size='regular'] {
		--h: var(--h-row);
		--px: 14px;
		--fw: var(--fw-bold);
	}
	button[data-variant='primary'][data-size='regular'] {
		--px: 16px;
	}
	button[data-size='compact'] {
		--h: var(--h-ctl);
		--px: 12px;
	}
	button[data-size='bar'] {
		--h: var(--h-ctl-bar);
		--px: 10px;
	}
	button[data-size='dense'] {
		--h: var(--h-ctl-dense);
		--px: 10px;
		--fs: var(--fs-small);
	}

	button {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		gap: 7px;
		height: var(--h);
		padding: 0 var(--px);
		border: 1px solid var(--bd-c);
		border-radius: var(--r);
		background: var(--bg);
		color: var(--fg);
		font-size: var(--fs, var(--fs-body));
		font-weight: var(--fw);
		white-space: nowrap;
		transition: background var(--t-fast) var(--ease);
	}

	/* El caret ya separa por sí mismo, así que ese lado cierra 2px antes. */
	button.caret {
		padding: 0 12px 0 14px;
	}
	button.full {
		width: 100%;
	}
	svg {
		opacity: var(--caret-op, 0.7);
	}

	/* Sin color propio de apuntado se queda como estaba: quien quiera cambiar declara el suyo. */
	button:hover:not(:disabled) {
		background: var(--bg-h, var(--bg));
		border-color: var(--bd-h, var(--bd-c));
		color: var(--fg-h, var(--fg));
	}

	button[data-variant='primary']:active:not(:disabled) {
		background: var(--pri-a);
	}

	button:disabled {
		opacity: 0.45;
		cursor: not-allowed;
	}
</style>
