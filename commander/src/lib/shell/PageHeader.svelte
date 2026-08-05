<script lang="ts">
	import type { Snippet } from 'svelte';

	export interface SubPart {
		/** La cifra, en mono. */
		value: string;
		/** La palabra, en la familia de interfaz. */
		text: string;
	}

	interface Props {
		title: string;
		/** Dato del sistema bajo el título: subdominio, identificador. Va en mono. */
		subtitle?: string | undefined;
		/** Recuentos compuestos: cifra en mono, palabra en interfaz. */
		parts?: SubPart[];
		/** Una sola acción primaria por pantalla, a la derecha. */
		action?: Snippet;
	}

	const { title, subtitle, parts = [], action }: Props = $props();
</script>

<div class="head">
	<h1>{title}</h1>
	{#if subtitle}<span class="mono subtitle">{subtitle}</span>{/if}
	<div class="parts">
		{#each parts as part, i (i)}
			<span class="part">
				<span class="sep">·</span>
				<span class="mono">{part.value}</span>
				<span>{part.text}</span>
			</span>
		{/each}
	</div>
	<div class="spacer"></div>
	{#if action}{@render action()}{/if}
</div>

<style>
	.head {
		display: flex;
		align-items: center;
		gap: 12px;
		min-height: var(--h-row);
		padding: 14px 16px 0;
	}

	h1 {
		margin: 0;
		flex: 0 1 auto;
		max-width: 520px;
		font-size: var(--fs-title);
		font-weight: var(--fw-bold);
		letter-spacing: var(--ls-title);
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.subtitle {
		font-size: var(--fs-small);
		color: var(--tx3);
		white-space: nowrap;
	}

	.parts {
		display: flex;
		align-items: baseline;
		gap: 7px;
		min-width: 0;
	}

	.part {
		display: flex;
		align-items: baseline;
		gap: 4px;
		font-size: var(--fs-small);
		color: var(--tx3);
		white-space: nowrap;
	}

	.part .mono {
		color: var(--tx2);
	}

	.sep {
		color: var(--tx4);
	}

	.spacer {
		flex: 1;
	}
</style>
