<script lang="ts">
	import type { DemoEntry } from '$lib/uikit/registry';

	const { entry }: { entry: DemoEntry } = $props();
</script>

<article>
	<div class="head">
		<h3>{entry.name}</h3>
		{#if entry.notes}<p>{entry.notes}</p>{/if}
		<div class="spacer"></div>
		<!-- La ruta convierte la guía en un índice: se ve la pieza y se sabe dónde leerla. -->
		<span class="mono src">{entry.source.replace('/src/lib/', '')}</span>
	</div>
	<div class="canvas">
		<entry.component />
	</div>
</article>

<style>
	article {
		margin-bottom: 12px;
		border: 1px solid var(--bd2);
		border-radius: var(--r);
		background: var(--bg-card);
		overflow: hidden;
	}

	.head {
		display: flex;
		align-items: baseline;
		gap: 10px;
		min-height: var(--h-card-head);
		padding: 8px 14px;
		border-bottom: 1px solid var(--bd2);
		background: var(--bg-fill);
	}

	h3 {
		margin: 0;
		font-size: var(--fs-card);
		font-weight: var(--fw-bold);
	}

	p {
		margin: 0;
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
		padding: 16px 14px;
	}
</style>
