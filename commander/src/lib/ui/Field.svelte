<script lang="ts">
	import type { Snippet } from 'svelte';

	interface Props {
		label: string;
		/** Se pasa al control para atarlo al rótulo sin que quien llama invente ids. */
		children: Snippet<[string]>;
		hint?: string;
		/** El error dice qué pasó y qué hacer. Convive con la pista: son dos cosas distintas. */
		error?: string;
		/** Abre grupo dentro de un formulario largo: una línea arriba en vez de un subtítulo. */
		separated?: boolean;
		/** Lo que cuelga del campo —«crear una tarifa», «ver el cliente»— y no es el control. */
		after?: Snippet;
		id?: string;
	}

	const {
		label,
		children,
		hint,
		error,
		separated = false,
		after,
		id = `f${Math.random().toString(36).slice(2, 9)}`
	}: Props = $props();
</script>

<div class:separated>
	<label for={id}>{label}</label>
	{@render children(id)}
	{#if error}<p class="error">{error}</p>{/if}
	{#if hint}<p class="hint">{hint}</p>{/if}
	{#if after}<div class="after">{@render after()}</div>{/if}
</div>

<style>
	.separated {
		border-top: 1px solid var(--bd2);
		padding-top: 12px;
	}

	/* El rótulo es versalita, no texto: se lee como el nombre de una columna, y así lo único con
	   peso de texto dentro del campo es el dato. */
	label {
		display: block;
		margin-bottom: 5px;
		font-size: var(--fs-small);
		letter-spacing: var(--ls-caps);
		text-transform: uppercase;
		color: var(--tx2);
	}

	p {
		margin: 4px 0 0;
		font-size: var(--fs-small);
	}

	.hint {
		color: var(--tx3);
	}

	.error {
		color: var(--er);
	}

	.after {
		margin-top: 6px;
		font-size: var(--fs-small);
		line-height: 14px;
	}
</style>
