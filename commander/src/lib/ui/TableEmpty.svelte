<script lang="ts">
	import type { Snippet } from 'svelte';

	interface Props {
		/** Una pantalla vacía es una invitación a actuar, no un aviso de que algo falló. */
		message: string;
		/** El mando que resuelve el vacío: quitar filtros, provisionar la primera. */
		action?: Snippet;
	}

	const { message, action }: Props = $props();
</script>

<div class="empty">
	<p>{message}</p>
	{#if action}{@render action()}{/if}
</div>

<style>
	.empty {
		padding: 48px 16px;
		text-align: center;
		color: var(--tx3);
	}

	p {
		margin: 0 0 12px;
		font-size: var(--fs-card);
	}
</style>
