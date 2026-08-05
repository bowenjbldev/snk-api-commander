<script lang="ts">
	import { anchor, type DemoSection } from '$lib/uikit/registry';

	interface Props {
		sections: DemoSection[];
		filter?: string;
	}

	let { sections, filter = $bindable() }: Props = $props();
</script>

<nav aria-label="Piezas del sistema">
	<div class="search">
		<!-- Con setenta entradas, buscar es más rápido que recorrer. Filtra el índice Y el contenido:
		     si no, se encuentra el nombre y hay que seguir buscando la pieza a mano. -->
		<input
			class="mono"
			type="search"
			bind:value={filter}
			placeholder="Filtrar piezas"
			aria-label="Filtrar piezas"
		/>
	</div>

	<div class="tree">
		{#each sections as section (section.group)}
			<a class="group" href="#{anchor(section.group)}">
				{section.group}
				<span class="mono n">{section.entries.length}</span>
			</a>
			{#each section.entries as entry (entry.source)}
				<a class="leaf" href="#{anchor(entry.name)}">{entry.name}</a>
			{/each}
		{/each}

		{#if sections.length === 0}
			<p class="empty">Ninguna pieza se llama así.</p>
		{/if}
	</div>
</nav>

<style>
	nav {
		display: flex;
		flex-direction: column;
		min-height: 0;
		border-right: 1px solid var(--bd2);
		background: var(--bg-card);
	}

	.search {
		flex: 0 0 auto;
		padding: 10px;
		border-bottom: 1px solid var(--bd2);
	}

	input {
		width: 100%;
		height: var(--h-ctl);
		padding: 0 8px;
		border: 1px solid var(--bd);
		border-radius: var(--r);
		background: var(--bg-fill);
		font-size: var(--fs-body);
	}

	input::placeholder {
		color: var(--tx3);
	}

	.tree {
		flex: 1;
		min-height: 0;
		overflow: auto;
		padding: 8px 8px 32px;
	}

	/* El grupo se lee como rótulo de sección, no como otra entrada más de la lista. */
	.group {
		display: flex;
		align-items: center;
		justify-content: space-between;
		height: var(--h-ctl);
		margin-top: 10px;
		padding: 0 8px;
		font-size: var(--fs-micro);
		font-weight: var(--fw-bold);
		letter-spacing: var(--ls-caps);
		text-transform: uppercase;
		color: var(--tx3);
	}

	.group:first-child {
		margin-top: 0;
	}

	.group:hover {
		color: var(--pri);
	}

	.n {
		font-size: var(--fs-micro);
		color: var(--tx4);
		letter-spacing: 0;
	}

	.leaf {
		display: block;
		height: var(--h-ctl-bar);
		padding: 0 8px;
		border-radius: var(--r);
		font-size: var(--fs-body);
		line-height: var(--h-ctl-bar);
		color: var(--tx2);
	}

	.leaf:hover {
		background: var(--bg-hover);
		color: var(--pri);
	}

	.empty {
		margin: 12px 8px;
		font-size: var(--fs-small);
		color: var(--tx3);
	}
</style>
