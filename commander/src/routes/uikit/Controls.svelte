<script lang="ts">
	import { Theme } from '$lib/model/enums';
	import { theme } from '$lib/state/theme.svelte';

	interface Props {
		onCard: boolean;
		reduced: boolean;
		onSurface: () => void;
		onMotion: () => void;
	}

	const { onCard, reduced, onSurface, onMotion }: Props = $props();
</script>

<div class="controls">
	<button
		type="button"
		onclick={() => {
			theme.toggle();
		}}
	>
		Tema: <span class="mono">{theme.value === Theme.Dark ? 'oscuro' : 'claro'}</span>
	</button>
	<button type="button" onclick={onSurface} aria-pressed={onCard}>
		Fondo: <span class="mono">{onCard ? '--bg-card' : '--bg-app'}</span>
	</button>
	<button type="button" onclick={onMotion} aria-pressed={reduced}>
		Movimiento: <span class="mono">{reduced ? 'reducido' : 'normal'}</span>
	</button>
</div>

<style>
	.controls {
		display: flex;
		gap: 6px;
	}

	button {
		height: var(--h-ctl);
		padding: 0 10px;
		border: 1px solid var(--bd);
		border-radius: var(--r);
		background: var(--bg-card);
		color: var(--tx2);
		font-size: var(--fs-body);
		transition: border-color var(--t-fast) var(--ease);
	}

	button:hover {
		border-color: var(--pri);
		color: var(--pri);
	}

	button[aria-pressed='true'] {
		border-color: var(--pri-bd);
		background: var(--pri-bg);
		color: var(--pri);
	}

	span {
		font-size: var(--fs-small);
		color: var(--tx);
	}

	button:hover span,
	button[aria-pressed='true'] span {
		color: inherit;
	}
</style>
