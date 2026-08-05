<script lang="ts">
	import { Tone } from '$lib/model/enums';
	import { trapFocus } from './focus-trap';

	export interface MenuItem {
		label: string;
		run: () => void;
		tone?: Tone;
		disabled?: boolean;
	}

	interface Props {
		items: MenuItem[];
		/** Dónde ancla, en coordenadas de ventana: sale del botón que lo abrió. */
		x: number;
		y: number;
		onclose: () => void;
		label?: string;
	}

	const { items, x, y, onclose, label = 'Acciones' }: Props = $props();
</script>

<svelte:window
	onkeydown={(event: KeyboardEvent) => {
		if (event.key === 'Escape') onclose();
	}}
/>

<div class="catch" role="presentation" onclick={onclose}></div>
<div
	class="menu"
	role="menu"
	aria-label={label}
	tabindex="-1"
	use:trapFocus
	style:left="{x}px"
	style:top="{y}px"
>
	{#each items as item (item.label)}
		<button
			type="button"
			role="menuitem"
			data-tone={item.tone ?? Tone.Neutral}
			disabled={item.disabled}
			onclick={() => {
				item.run();
				onclose();
			}}>{item.label}</button
		>
	{/each}
</div>

<style>
	/* Recoge el clic de fuera sin velar la pantalla: un menú no es una capa modal. */
	.catch {
		position: fixed;
		inset: 0;
		z-index: 59;
	}

	.menu {
		position: fixed;
		z-index: 60;
		width: 214px;
		padding: 4px;
		border: 1px solid var(--bd);
		border-radius: var(--r);
		background: var(--bg-elev);
		box-shadow: var(--sh);
	}

	button {
		display: flex;
		align-items: center;
		gap: 8px;
		width: 100%;
		height: var(--h-ctl);
		padding: 0 8px;
		border: 0;
		border-radius: var(--r-tag);
		background: transparent;
		color: var(--tx);
		font-size: var(--fs-body);
		text-align: left;
	}

	button:hover:not(:disabled) {
		background: var(--bg-hover);
	}

	/* El color del ítem es el de su efecto, no el de su categoría: restablecer es verde porque
	   devuelve suministro, y reencolar es azul porque solo vuelve a intentar un alta. */
	button[data-tone='ok'] {
		color: var(--ok);
	}
	button[data-tone='warn'] {
		color: var(--wn);
	}
	button[data-tone='error'] {
		color: var(--er);
	}
	button[data-tone='info'] {
		color: var(--in);
	}

	/* Después de los tonos a propósito: un ítem imposible se apaga aunque diga «cortar». */
	button:disabled {
		color: var(--tx4);
		cursor: not-allowed;
	}
</style>
