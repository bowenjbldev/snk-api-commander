<script lang="ts">
	import type { Snippet } from 'svelte';
	import Button from './Button.svelte';
	import { ControlSize } from './kinds';
	import { trapFocus } from './focus-trap';

	interface Props {
		title: string;
		onclose: () => void;
		children: Snippet;
		/** El mando se nombra por lo que hace: «Crear cliente», no «Guardar». */
		confirmLabel: string;
		onconfirm?: () => void;
		confirmDisabled?: boolean;
	}

	const {
		title,
		onclose,
		children,
		confirmLabel,
		onconfirm,
		confirmDisabled = false
	}: Props = $props();
</script>

<svelte:window
	onkeydown={(event: KeyboardEvent) => {
		if (event.key === 'Escape') onclose();
	}}
/>

<!-- El equivalente de teclado del clic en el velo es Escape, arriba. -->
<!-- svelte-ignore a11y_click_events_have_key_events -->
<div class="scrim" role="presentation" onclick={onclose}>
	<div
		class="drawer"
		role="dialog"
		aria-modal="true"
		aria-label={title}
		tabindex="-1"
		use:trapFocus
		onclick={(event: MouseEvent) => {
			event.stopPropagation();
		}}
	>
		<header><span class="title">{title}</span></header>
		<div class="body">{@render children()}</div>
		<footer>
			<Button size={ControlSize.Compact} onclick={onclose}>Cancelar</Button>
			<button type="button" class="ok" disabled={confirmDisabled} onclick={() => onconfirm?.()}>
				{confirmLabel}
			</button>
		</footer>
	</div>
</div>

<style>
	/* Entra por la derecha: el formulario largo llega al lado de lo que lo pidió, no encima. */
	.scrim {
		position: fixed;
		inset: 0;
		z-index: 75;
		display: flex;
		justify-content: flex-end;
		background: var(--scrim);
	}

	.drawer {
		display: flex;
		flex-direction: column;
		width: 420px;
		max-width: 94vw;
		height: 100%;
		border-left: 1px solid var(--bd);
		background: var(--bg-elev);
		box-shadow: var(--sh);
	}

	header {
		display: flex;
		align-items: center;
		flex: 0 0 var(--h-app-head);
		height: var(--h-app-head);
		padding: 0 14px;
		border-bottom: 1px solid var(--bd2);
	}

	.title {
		font-size: var(--fs-title);
		font-weight: var(--fw-bold);
	}

	.body {
		display: flex;
		flex: 1;
		flex-direction: column;
		gap: 12px;
		padding: 14px;
		overflow: auto;
	}

	footer {
		display: flex;
		align-items: center;
		justify-content: flex-end;
		gap: 8px;
		flex: 0 0 52px;
		height: 52px;
		padding: 0 14px;
		border-top: 1px solid var(--bd2);
	}

	/* Un cajón siempre acaba en un alta o una edición: su mando es el primario, sin tonos. */
	.ok {
		height: var(--h-ctl);
		padding: 0 14px;
		border: 0;
		border-radius: var(--r);
		background: var(--pri);
		color: var(--on-pri);
		font-size: var(--fs-body);
		font-weight: var(--fw-bold);
	}
	.ok:disabled {
		opacity: 0.45;
	}
</style>
