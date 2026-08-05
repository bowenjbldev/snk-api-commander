<script lang="ts" generics="T">
	import { PageSize, type PagerView } from '$lib/table/pager.svelte';

	const { pager }: { pager: PagerView<T> } = $props();

	const SIZES = [PageSize.Ten, PageSize.TwentyFive, PageSize.Fifty, PageSize.Hundred];
</script>

<div class="footer">
	<span class="mono range">{pager.range}</span>
	<div class="spacer"></div>
	<!-- Los mandos no se esconden con una sola página: el pie no cambia de forma al filtrar,
		 solo se apagan los pasos. -->
	<button
		type="button"
		class="step"
		title="Página anterior"
		disabled={pager.page === 1}
		onclick={() => {
			pager.prev();
		}}>‹</button
	>
	{#each pager.numbers as number (number)}
		<button
			type="button"
			class="mono number"
			class:current={number === pager.page}
			aria-current={number === pager.page ? 'page' : undefined}
			onclick={() => {
				pager.goto(number);
			}}>{number}</button
		>
	{/each}
	<button
		type="button"
		class="step"
		title="Página siguiente"
		disabled={pager.page === pager.pages}
		onclick={() => {
			pager.next();
		}}>›</button
	>
	<select
		class="mono"
		value={String(pager.size)}
		onchange={(event) => {
			pager.setSize(Number(event.currentTarget.value));
		}}
	>
		{#each SIZES as size (size)}
			<option value={String(size)}>{size}</option>
		{/each}
	</select>
	<span class="per">por página</span>
</div>

<style>
	.footer {
		display: flex;
		align-items: center;
		gap: 6px;
		height: var(--h-foot);
		padding: 0 10px;
		border-top: 1px solid var(--bd2);
		background: var(--bg-fill);
	}

	.range {
		font-size: var(--fs-small);
		color: var(--tx2);
	}

	.spacer {
		flex: 1;
	}

	.step,
	.number {
		height: var(--h-ctl-dense);
		border: 1px solid var(--bd);
		border-radius: var(--r);
	}

	.step {
		width: var(--h-ctl-dense);
		background: var(--bg-card);
		color: var(--tx);
		font-size: var(--fs-body);
		line-height: 1;
	}

	.step:disabled {
		color: var(--tx4);
	}

	/* Sin fondo hasta ser la página actual: una fila de cajas blancas pesaría más que el pie
	   entero, y el único número que hay que encontrar es el de ahora. */
	.number {
		min-width: var(--h-ctl-dense);
		padding: 0 6px;
		background: transparent;
		font-size: var(--fs-small);
		color: var(--tx2);
	}

	.number.current {
		border-color: var(--pri-bd);
		background: var(--pri-bg);
		color: var(--pri);
	}

	select {
		height: var(--h-ctl-dense);
		margin-left: 6px;
		padding: 0 4px;
		border: 1px solid var(--bd);
		border-radius: var(--r);
		background: var(--bg-card);
		font-size: var(--fs-small);
	}

	.per {
		font-size: var(--fs-small);
		color: var(--tx3);
	}
</style>
