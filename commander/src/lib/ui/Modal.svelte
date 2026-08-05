<script lang="ts">
	import type { Snippet } from 'svelte';
	import { Tone } from '$lib/model/enums';
	import Button from './Button.svelte';
	import { ButtonVariant, ControlSize } from './kinds';
	import { trapFocus } from './focus-trap';

	interface Props {
		title: string;
		onclose: () => void;
		children: Snippet;
		/** Tiñe la marca de la cabecera Y el mando de confirmar: son el mismo color, siempre. */
		tone?: Tone;
		/** Sin `confirm` la decisión vive en el cuerpo y el pie trae un único botón de cierre. */
		confirmLabel?: string;
		onconfirm?: () => void;
		confirmDisabled?: boolean;
		/** La capa que solo ofrece caminos cierra con «Cerrar», no con «Cancelar». */
		cancelLabel?: string;
	}

	const {
		title,
		onclose,
		children,
		tone = Tone.Neutral,
		confirmLabel,
		onconfirm,
		confirmDisabled = false,
		cancelLabel = 'Cancelar'
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
		class="modal"
		data-tone={tone}
		role="dialog"
		aria-modal="true"
		aria-label={title}
		tabindex="-1"
		use:trapFocus
		onclick={(event: MouseEvent) => {
			event.stopPropagation();
		}}
	>
		<header>
			<span class="bar"></span>
			<span>{title}</span>
		</header>
		<div class="body">{@render children()}</div>
		<footer>
			<Button size={ControlSize.Compact} onclick={onclose}>{cancelLabel}</Button>
			{#if confirmLabel}
				<Button
					variant={ButtonVariant.Primary}
					size={ControlSize.Compact}
					{tone}
					disabled={confirmDisabled}
					onclick={() => onconfirm?.()}
				>
					{confirmLabel}
				</Button>
			{/if}
		</footer>
	</div>
</div>

<style>
	.scrim {
		position: fixed;
		inset: 0;
		z-index: 80;
		display: grid;
		place-items: center;
		background: var(--scrim);
	}

	/* El tono vive una vez en la caja y lo leen la marca y el mando: no pueden discrepar. */
	.modal {
		--tone: var(--pri);
		width: 460px;
		max-width: 92vw;
		border: 1px solid var(--bd);
		border-radius: var(--r);
		background: var(--bg-elev);
		box-shadow: var(--sh);
		animation: rise var(--t-pulse) ease-out;
	}

	.modal[data-tone='ok'] {
		--tone: var(--ok);
	}
	.modal[data-tone='warn'] {
		--tone: var(--wn);
	}
	.modal[data-tone='error'] {
		--tone: var(--er);
	}
	.modal[data-tone='info'] {
		--tone: var(--in);
	}
	header,
	footer {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 0 14px;
	}
	header {
		height: var(--h-card-head);
		border-bottom: 1px solid var(--bd2);
		font-size: var(--fs-card);
		font-weight: var(--fw-bold);
	}
	footer {
		justify-content: flex-end;
		height: 44px;
		border-top: 1px solid var(--bd2);
	}

	/* Marca de 3 px que la línea de suministro: el tono se lee antes que el título. */
	.bar {
		width: 3px;
		height: 18px;
		border-radius: var(--r-line);
		background: var(--tone);
	}
	.body {
		padding: 14px;
	}
	.body :global(p) {
		margin: 0 0 12px;
		color: var(--tx2);
		font-size: var(--fs-body);
		line-height: 1.6;
	}

</style>
