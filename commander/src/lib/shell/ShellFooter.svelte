<script lang="ts">
	import { rel } from '$lib/format';
	import KeyHint from '$lib/ui/KeyHint.svelte';

	interface Props {
		/** Instante de la última carga. Nulo mientras no haya llegado nada. */
		loadedAt: number | null;
		now: number;
		attention: number;
		operator: string;
		onattention: () => void;
	}

	const { loadedAt, now, attention, operator, onattention }: Props = $props();

	const word = $derived(attention === 1 ? 'pide atención' : 'piden atención');
</script>

<!-- Ahí aterriza el estado del canal, sin competir con el contenido. La consola no promete tiempo
	 real: una salud de contador puede llevar seis horas de retraso, y decir «ahora mismo» sería
	 mentir. Lo único vivo es el paso del tiempo. -->
<footer>
	<span class="dot" aria-hidden="true"></span>
	<span>Cadencia de 10 min</span>
	<span class="sep">│</span>
	{#if loadedAt === null}
		<span class="muted">Cargando datos…</span>
	{:else}
		<span>Datos de hace <span class="mono">{rel(now - loadedAt)}</span></span>
	{/if}
	<span class="sep">│</span>
	<button type="button" onclick={onattention} class:urgent={attention > 0}>
		<span class="mono">{attention}</span>
		<span>{word}</span>
	</button>
	<div class="spacer"></div>
	<span class="muted">{operator}</span>
	<span class="sep">│</span>
	<KeyHint keys="⌘K" />
</footer>

<style>
	footer {
		display: flex;
		align-items: center;
		gap: 8px;
		grid-column: 1 / -1;
		height: var(--h-shell-foot);
		padding: 0 12px;
		border-top: 1px solid var(--bd2);
		background: var(--bg-card);
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.dot {
		width: 6px;
		height: 6px;
		border-radius: 50%;
		background: var(--ok);
	}

	.sep {
		color: var(--bd);
	}

	.muted {
		color: var(--tx3);
	}

	.spacer {
		flex: 1;
	}

	button {
		display: flex;
		align-items: center;
		gap: 5px;
		padding: 0;
		border: 0;
		background: transparent;
		color: inherit;
		font-size: var(--fs-small);
	}

	button:hover {
		color: var(--pri);
	}

	button.urgent {
		color: var(--er);
	}
</style>
