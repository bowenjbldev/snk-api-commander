<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Actions,
		name: 'IconButton',
		notes:
			'24 dentro de una fila, 32 en la cabecera. Siempre con rótulo para el lector de pantalla.'
	};
</script>

<script lang="ts">
	import IconButton from './IconButton.svelte';
	import KeyHint from './KeyHint.svelte';
</script>

{#snippet bell()}
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
{/snippet}

{#snippet exit()}
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
{/snippet}

<div class="row">
	<span class="note">24 · fila</span>
	<IconButton label="Acciones">⋯</IconButton>
	<IconButton label="Marcar como visto">×</IconButton>
	<IconButton label="Sin permiso" disabled>⋯</IconButton>
	<span class="note wide">al apuntar, el escalón fuerte: la fila ya tiene fondo debajo</span>
</div>

<div class="row">
	<span class="note">32 · cabecera</span>
	<IconButton label="Bandeja de atención" size={32} children={bell} />
	<IconButton label="Cerrar sesión" size={32} danger children={exit} />
	<IconButton label="Control" size={32} bordered>⋯</IconButton>
	<span class="note wide">el suave; el de salir tira al rojo; el de caja, al primario</span>
</div>

<div class="row">
	<span class="note">glifos</span>
	<KeyHint keys="⌘K" />
	<KeyHint keys="↑↓ moverse" />
	<KeyHint keys="⏎ ejecutar" />
</div>

<style>
	.row {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 10px;
		margin-bottom: 10px;
	}

	.row:last-child {
		margin-bottom: 0;
	}

	.note {
		flex: 0 0 96px;
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.wide {
		flex: 0 1 auto;
	}
</style>
