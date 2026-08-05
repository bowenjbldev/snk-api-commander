<script lang="ts">
	import type { Snippet } from 'svelte';

	interface Props {
		title?: string;
		/** Dato del sistema junto al título: un recuento, una edad. Va en mono. */
		note?: string;
		/** Apunte al final de la cabecera, alineado a la derecha. */
		aside?: Snippet;
		/** Mando de la cabecera, después del apunte. */
		actions?: Snippet;
		/** Franja bajo la cabecera para la advertencia que vale para todo el contenido. */
		subhead?: string;
		/** Sin relleno de cuerpo: una tabla va a sangre dentro de su tarjeta. */
		flush?: boolean;
		children: Snippet;
	}

	const { title, note, aside, actions, subhead, flush = false, children }: Props = $props();
</script>

<section class="card" class:clip={flush}>
	{#if title}
		<header>
			<span class="title">{title}</span>
			{#if note}<span class="mono note">{note}</span>{/if}
			<div class="spacer"></div>
			{#if aside}{@render aside()}{/if}
			{#if actions}{@render actions()}{/if}
		</header>
	{/if}
	{#if subhead}<p class="subhead">{subhead}</p>{/if}
	<div class="body" class:flush>{@render children()}</div>
</section>

<style>
	/* Jerarquía de planos por relleno, no por sombra: fondo de aplicación → tarjeta → cabecera. */
	.card {
		background: var(--bg-card);
		border: 1px solid var(--bd2);
		border-radius: var(--r);
	}

	/* Las filas de una tabla son un continuo: el radio lo pone la tarjeta y recorta. */
	.card.clip {
		overflow: hidden;
	}

	/* La cabecera tiene alto fijo, así que lo que no quepa se recorta en vez de derramarse sobre la
	   tarjeta de al lado. El que cede es el título: el mando de la derecha nunca se parte. */
	header {
		display: flex;
		align-items: center;
		gap: 8px;
		height: var(--h-card-head);
		padding: 0 12px;
		border-bottom: 1px solid var(--bd2);
		overflow: hidden;
		white-space: nowrap;
	}

	/* En el plano de relleno para que se lea como parte de la cabecera, no como el primer dato. */
	.subhead {
		margin: 0;
		padding: 7px 12px;
		border-bottom: 1px solid var(--bd2);
		background: var(--bg-fill);
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.title {
		flex: 0 1 auto;
		min-width: 0;
		font-size: var(--fs-card);
		font-weight: var(--fw-bold);
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.note {
		flex: 0 0 auto;
		font-size: var(--fs-small);
		color: var(--tx3);
		white-space: nowrap;
	}

	.spacer {
		flex: 1;
	}

	.body {
		padding: 12px;
	}

	.body.flush {
		padding: 0;
	}
</style>
