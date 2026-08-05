<script lang="ts">
	import { docsFor } from '$lib/uikit/props';
	import type { DemoEntry } from '$lib/uikit/registry';
	import PropsTable from './PropsTable.svelte';

	const { entry, anchor }: { entry: DemoEntry; anchor: string } = $props();

	const docs = $derived(docsFor(entry.source, entry.components));
	const propCount = $derived(docs.reduce((n, d) => n + d.props.length, 0));
</script>

<article id={anchor}>
	<div class="head">
		<h3><a href="#{anchor}">{entry.name}</a></h3>
		{#if entry.notes}<p>{entry.notes}</p>{/if}
		<div class="spacer"></div>
		<!-- La ruta convierte la guía en un índice: se ve la pieza y se sabe dónde leerla. -->
		<span class="mono src">{entry.source.replace('/src/lib/', '')}</span>
	</div>

	<div class="canvas">
		<entry.component />
	</div>

	{#if propCount > 0}
		<details>
			<summary>
				API
				<span class="mono n">{propCount}</span>
				<span class="word">{propCount === 1 ? 'propiedad' : 'propiedades'}</span>
			</summary>
			<div class="api">
				{#each docs as doc (doc.path)}
					<PropsTable {doc} />
				{/each}
			</div>
		</details>
	{/if}
</article>

<style>
	article {
		margin-bottom: 14px;
		border: 1px solid var(--bd2);
		border-radius: var(--r);
		background: var(--bg-card);
		overflow: hidden;
		scroll-margin-top: 16px;
	}

	.head {
		display: flex;
		align-items: baseline;
		gap: 10px;
		min-height: var(--h-card-head);
		padding: 8px 16px;
		border-bottom: 1px solid var(--bd2);
		background: var(--bg-fill);
	}

	h3 {
		margin: 0;
		font-size: var(--fs-card);
		font-weight: var(--fw-bold);
	}

	/* El título ES el enlace permanente: quien quiera mandar «mira esto» no tiene que buscar cómo. */
	h3 a {
		color: var(--tx);
	}

	h3 a:hover {
		color: var(--pri);
	}

	p {
		margin: 0;
		flex: 0 1 auto;
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.spacer {
		flex: 1;
	}

	.src {
		font-size: var(--fs-micro);
		color: var(--tx4);
		white-space: nowrap;
	}

	.canvas {
		padding: 20px 16px;
	}

	/* La API va plegada: quien viene a mirar el sistema no quiere leer tipos, y quien viene a
	   usarlo la abre una vez. Es `<details>` nativo, así que el buscador del navegador la encuentra
	   igual y no hace falta ni una línea de JavaScript. */
	details {
		border-top: 1px solid var(--bd2);
		background: var(--bg-fill);
	}

	summary {
		display: flex;
		align-items: center;
		gap: 6px;
		height: var(--h-ctl);
		padding: 0 16px;
		font-size: var(--fs-small);
		color: var(--tx2);
		cursor: pointer;
		user-select: none;
	}

	summary:hover {
		color: var(--pri);
	}

	.n {
		color: var(--tx3);
	}

	.word {
		color: var(--tx3);
	}

	.api {
		display: flex;
		flex-direction: column;
		gap: 18px;
		padding: 4px 8px 16px;
		background: var(--bg-card);
		border-top: 1px solid var(--bd2);
	}
</style>
