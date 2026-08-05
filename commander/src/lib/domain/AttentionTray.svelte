<script lang="ts">
	import Button from '$lib/ui/Button.svelte';
	import IconButton from '$lib/ui/IconButton.svelte';
	import { ControlSize } from '$lib/ui/kinds';
	import type { AttentionItem } from './attention';

	interface Props {
		items: AttentionItem[];
		onrun?: (item: AttentionItem) => void;
		ondismiss?: (item: AttentionItem) => void;
	}

	const { items, onrun, ondismiss }: Props = $props();
</script>

{#each items as item (item.id)}
	<div class="row">
		<span class="mark" data-tone={item.tone}></span>
		<div class="text">
			<div class="title" data-tone={item.tone}>{item.title}</div>
			<div class="mono body">{item.body}</div>
		</div>
		<Button
			size={ControlSize.Dense}
			onclick={() => {
				onrun?.(item);
			}}>{item.action}</Button
		>
		{#if ondismiss}
			<IconButton
				label="Marcar como visto"
				onclick={() => {
					ondismiss(item);
				}}>×</IconButton
			>
		{/if}
	</div>
{/each}

{#if items.length === 0}
	<div class="empty">Nada exige decisión ahora mismo.</div>
{/if}

<style>
	.row {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 8px 12px;
		border-bottom: 1px solid var(--bd2);
	}

	.row:hover {
		background: var(--bg-hover);
	}

	.row:last-child {
		border-bottom: 0;
	}

	.mark {
		flex: 0 0 3px;
		width: 3px;
		height: 26px;
		border-radius: var(--r-line);
		background: var(--tx4);
	}

	.mark[data-tone='ok'] {
		background: var(--ok);
	}
	.mark[data-tone='warn'] {
		background: var(--wn);
	}
	.mark[data-tone='error'] {
		background: var(--er);
	}
	.mark[data-tone='info'] {
		background: var(--in);
	}

	.text {
		flex: 1;
		min-width: 0;
	}

	.title {
		font-size: var(--fs-body);
		font-weight: var(--fw-bold);
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.title[data-tone='warn'] {
		color: var(--wn);
	}
	.title[data-tone='error'] {
		color: var(--er);
	}
	.title[data-tone='info'] {
		color: var(--in);
	}

	.body {
		font-size: var(--fs-small);
		color: var(--tx2);
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.empty {
		padding: 32px;
		text-align: center;
		font-size: var(--fs-body);
		color: var(--tx3);
	}
</style>
