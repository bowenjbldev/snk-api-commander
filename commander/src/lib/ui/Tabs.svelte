<script lang="ts">
	export interface Tab {
		value: string;
		label: string;
		/** Ruta a la que salta. Las pestañas de una instancia son rutas, no estado local. */
		href?: string;
	}

	interface Props {
		tabs: Tab[];
		current: string;
		onselect?: (value: string) => void;
		label?: string;
	}

	const { tabs, current, onselect, label }: Props = $props();
</script>

<div class="tabs" role="tablist" aria-label={label}>
	{#each tabs as tab (tab.value)}
		{#if tab.href}
			<!-- El href llega ya resuelto por nav/routes.ts, que es quien conoce las rutas. -->
			<!-- eslint-disable-next-line svelte/no-navigation-without-resolve -->
			<a href={tab.href} role="tab" aria-selected={tab.value === current}>{tab.label}</a>
		{:else}
			<button
				type="button"
				role="tab"
				aria-selected={tab.value === current}
				onclick={() => onselect?.(tab.value)}>{tab.label}</button
			>
		{/if}
	{/each}
</div>

<style>
	.tabs {
		display: flex;
		gap: 2px;
		border-bottom: 1px solid var(--bd);
	}

	a,
	button {
		height: var(--h-tab);
		padding: 0 12px;
		border: 0;
		/* Solapa la línea del contenedor, para que la pestaña activa la corte. */
		border-bottom: 2px solid transparent;
		margin-bottom: -1px;
		background: transparent;
		color: var(--tx2);
		font-size: var(--fs-body);
		line-height: var(--h-tab);
		white-space: nowrap;
	}

	a:hover,
	button:hover {
		color: var(--pri);
	}

	[aria-selected='true'] {
		border-bottom-color: var(--pri);
		color: var(--pri);
		font-weight: var(--fw-bold);
	}
</style>
