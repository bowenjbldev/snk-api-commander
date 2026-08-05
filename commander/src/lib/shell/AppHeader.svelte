<script lang="ts">
	import { theme } from '$lib/state/theme.svelte';
	import Badge from '$lib/ui/Badge.svelte';
	import IconButton from '$lib/ui/IconButton.svelte';
	import KeyHint from '$lib/ui/KeyHint.svelte';

	interface Props {
		onsearch: () => void;
		onlogout: () => void;
		/** Cuántas cosas piden atención. Cero esconde el punto: no hay nada que acusar. */
		attention: number;
		onattention: () => void;
	}

	const { onsearch, onlogout, attention, onattention }: Props = $props();
</script>

<header>
	<button class="search" type="button" onclick={onsearch}>
		<svg
			width="12"
			height="12"
			viewBox="0 0 16 16"
			fill="none"
			stroke="currentColor"
			stroke-width="1.5"
			aria-hidden="true"
		>
			<circle cx="7" cy="7" r="4.5" />
			<path d="M10.5 10.5 14 14" />
		</svg>
		<span class="placeholder">Buscar instancias, clientes…</span>
		<KeyHint keys="⌘K" />
	</button>

	<div class="spacer"></div>

	<div class="bell">
		<IconButton label="Bandeja de atención" size={32} onclick={onattention}>
			<svg
				width="16"
				height="16"
				viewBox="0 0 16 16"
				fill="none"
				stroke="currentColor"
				stroke-width="1.3"
				stroke-linecap="round"
				aria-hidden="true"
			>
				<path d="M4.2 11.4V7.4a3.8 3.8 0 0 1 7.6 0v4" />
				<line x1="2.8" y1="11.4" x2="13.2" y2="11.4" />
				<path d="M6.6 13.2a1.5 1.5 0 0 0 2.8 0" />
			</svg>
		</IconButton>
		{#if attention > 0}
			<span class="pin"><Badge count={attention} /></span>
		{/if}
	</div>

	<IconButton
		label="Cambiar tema"
		size={32}
		onclick={() => {
			theme.toggle();
		}}
	>
		<svg
			width="16"
			height="16"
			viewBox="0 0 16 16"
			fill="none"
			stroke="currentColor"
			stroke-width="1.3"
			aria-hidden="true"
		>
			<circle cx="8" cy="8" r="5.2" />
			<!-- El glifo no cambia con el tema: rotula el mando, no el estado. Cambiarlo obligaría a
			     leerlo dos veces para saber si dice dónde estás o a dónde vas. -->
			<path d="M8 2.8 A5.2 5.2 0 0 1 8 13.2 Z" fill="currentColor" stroke="none" />
		</svg>
	</IconButton>

	<IconButton label="Cerrar sesión" size={32} danger onclick={onlogout}>
		<svg
			width="16"
			height="16"
			viewBox="0 0 16 16"
			fill="none"
			stroke="currentColor"
			stroke-width="1.3"
			stroke-linecap="round"
			aria-hidden="true"
		>
			<path d="M5 4.8 A4.6 4.6 0 1 0 11 4.8" />
			<line x1="8" y1="2.2" x2="8" y2="7.2" />
		</svg>
	</IconButton>
</header>

<style>
	header {
		display: flex;
		align-items: center;
		gap: 12px;
		flex: 0 0 var(--h-app-head);
		height: var(--h-app-head);
		padding: 0 max(16px, calc((100% - 1370px) / 2 + 16px));
		border-bottom: 1px solid var(--bd2);
		background: var(--bg-card);
	}

	.search {
		display: flex;
		align-items: center;
		gap: 7px;
		flex: 0 1 320px;
		min-width: 120px;
		height: var(--h-ctl);
		padding: 0 8px 0 9px;
		border: 1px solid var(--bd);
		border-radius: var(--r);
		background: var(--bg-fill);
		color: var(--tx4);
		font-size: var(--fs-body);
	}

	.search:hover {
		border-color: var(--pri);
		background: var(--bg-card);
	}

	.placeholder {
		flex: 1;
		text-align: left;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.spacer {
		flex: 1;
	}

	.bell {
		position: relative;
	}

	/* La píldora la resuelve `Badge`; aquí solo se decide dónde se cuelga. */
	.pin {
		position: absolute;
		top: 3px;
		right: 2px;
	}
</style>
