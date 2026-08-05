<script lang="ts">
	import type { Snippet } from 'svelte';
	import { Tone } from '$lib/model/enums';

	interface Props {
		tone?: Tone;
		/** Borde de trazos: un paso pendiente, no una avería. */
		pending?: boolean;
		title?: string;
		children: Snippet;
	}

	const { tone = Tone.Neutral, pending = false, title, children }: Props = $props();
</script>

<span class="tag" data-tone={tone} class:pending {title}>{@render children()}</span>

<style>
	/* Ancho mínimo fijo para que una columna de etiquetas cuadre en vertical. */
	.tag {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		min-width: 62px;
		height: var(--h-tag);
		padding: 0 4px;
		border: 1px solid var(--bd2);
		border-radius: var(--r-tag);
		background: var(--bg-fill2);
		color: var(--tx3);
		font-size: var(--fs-micro);
		letter-spacing: var(--ls-caps);
		text-transform: uppercase;
		font-weight: var(--fw-bold);
		white-space: nowrap;
	}

	.tag.pending {
		border-style: dashed;
		border-color: var(--bd);
		color: var(--tx2);
	}

	.tag[data-tone='ok'] {
		background: var(--ok-bg);
		border-color: var(--ok-bd);
		color: var(--ok);
	}
	.tag[data-tone='warn'] {
		background: var(--wn-bg);
		border-color: var(--wn-bd);
		color: var(--wn);
	}
	.tag[data-tone='error'] {
		background: var(--er-bg);
		border-color: var(--er-bd);
		color: var(--er);
	}
	.tag[data-tone='info'] {
		background: var(--in-bg);
		border-color: var(--in-bd);
		color: var(--in);
	}
</style>
