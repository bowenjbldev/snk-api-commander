<script lang="ts">
	import type { Snippet } from 'svelte';
	import { Tone } from '$lib/model/enums';

	interface Props {
		title: string;
		/** El cuerpo suele ser un motivo del sistema: mono. */
		body?: string | undefined;
		tone?: Tone;
		/** Borde de trazos: un paso pendiente, no una avería. */
		pending?: boolean;
		/** Dentro de una lista —la bandeja— el aviso pierde la caja y solo separa con una raya. */
		row?: boolean;
		/** Sustituye a la barra de tono: en el detalle va la línea de suministro de verdad. */
		leading?: Snippet;
		/** El mando que resuelve lo que el aviso denuncia. */
		action?: Snippet;
	}

	const {
		title,
		body,
		tone = Tone.Neutral,
		pending = false,
		row = false,
		leading,
		action
	}: Props = $props();
</script>

<div class="alert" data-tone={tone} class:pending class:row>
	{#if leading}{@render leading()}{:else}<span class="bar"></span>{/if}
	<div class="text">
		<div class="title">{title}</div>
		{#if body}<div class="mono body">{body}</div>{/if}
	</div>
	{#if action}{@render action()}{/if}
</div>

<style>
	.alert {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 10px 12px;
		border: 1px solid var(--bd);
		border-radius: var(--r);
		background: var(--bg-fill2);
	}

	.alert.pending {
		border-style: dashed;
	}

	.alert[data-tone='ok'] {
		border-color: var(--ok-bd);
		background: var(--ok-bg);
	}
	.alert[data-tone='warn'] {
		border-color: var(--wn-bd);
		background: var(--wn-bg);
	}
	.alert[data-tone='error'] {
		border-color: var(--er-bd);
		background: var(--er-bg);
	}
	.alert[data-tone='info'] {
		border-color: var(--in-bd);
		background: var(--in-bg);
	}

	/* Una lista de avisos no es una pila de cajas: dentro de la tarjeta el tono lo lleva solo la
	   barra, y del recuadro queda la raya que separa una decisión de la siguiente. */
	.alert.row {
		padding: 8px 12px;
		border: 0;
		border-bottom: 1px solid var(--bd2);
		border-radius: 0;
		background: transparent;
	}

	.alert.row:hover {
		background: var(--bg-hover);
	}

	/* Los mismos 3 px de la fila de la Flota. En caja se estira; en lista mide lo que el texto. */
	.bar {
		flex: 0 0 3px;
		width: 3px;
		align-self: stretch;
		border-radius: var(--r-line);
		background: var(--tx4);
	}

	.alert.row .bar {
		height: 26px;
		align-self: center;
	}

	.alert[data-tone='ok'] .bar {
		background: var(--ok);
	}
	.alert[data-tone='warn'] .bar {
		background: var(--wn);
	}
	.alert[data-tone='error'] .bar {
		background: var(--er);
	}
	.alert[data-tone='info'] .bar {
		background: var(--in);
	}

	.text {
		flex: 1;
		min-width: 0;
	}

	.title {
		font-size: var(--fs-body);
		font-weight: var(--fw-bold);
		color: var(--tx);
	}

	.alert[data-tone='ok'] .title {
		color: var(--ok);
	}
	.alert[data-tone='warn'] .title {
		color: var(--wn);
	}
	.alert[data-tone='error'] .title {
		color: var(--er);
	}
	.alert[data-tone='info'] .title {
		color: var(--in);
	}

	.body {
		font-size: var(--fs-small);
		color: var(--tx2);
	}

	/* En lista el ancho es del vecino: nada envuelve, todo se corta. */
	.alert.row .title,
	.alert.row .body {
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}
</style>
