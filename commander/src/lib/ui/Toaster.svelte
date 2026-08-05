<script lang="ts">
	import type { Tone } from '$lib/model/enums';

	export interface ToastItem {
		id: number;
		text: string;
		tone: Tone;
	}

	const { items }: { items: ToastItem[] } = $props();
</script>

<div class="toaster" aria-live="polite" aria-atomic="false">
	{#each items as toast (toast.id)}
		<div class="toast" data-tone={toast.tone}>{toast.text}</div>
	{/each}
</div>

<style>
	.toaster {
		position: fixed;
		right: 16px;
		bottom: 38px;
		z-index: 90;
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		gap: 6px;
	}

	.toast {
		max-width: 360px;
		padding: 8px 12px;
		border: 1px solid var(--bd);
		border-left: 3px solid var(--tx4);
		border-radius: var(--r);
		background: var(--bg-elev);
		box-shadow: var(--sh);
		color: var(--tx);
		font-size: var(--fs-body);
		animation: rise var(--t-pulse) ease-out;
	}

	.toast[data-tone='ok'] {
		border-color: var(--ok-bd);
		border-left-color: var(--ok);
	}
	.toast[data-tone='warn'] {
		border-color: var(--wn-bd);
		border-left-color: var(--wn);
	}
	.toast[data-tone='error'] {
		border-color: var(--er-bd);
		border-left-color: var(--er);
	}
	.toast[data-tone='info'] {
		border-color: var(--in-bd);
		border-left-color: var(--in);
	}
</style>
