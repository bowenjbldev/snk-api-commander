<script lang="ts">
	import { anchorFor, demos, sections } from '$lib/uikit/registry';
	import Controls from './Controls.svelte';
	import DemoCard from './DemoCard.svelte';

	let onCard = $state(false);
	let reduced = $state(false);

	function toggleMotion(): void {
		reduced = !reduced;
		document.documentElement.dataset['motion'] = reduced ? 'reduced' : '';
	}
</script>

<svelte:head><title>Commander · guía</title></svelte:head>

<div class="page" class:on-card={onCard}>
	<header>
		<h1>Guía</h1>
		<span class="mono count">{demos.length}</span>
		<span class="hint">piezas, descubiertas solas</span>
		<div class="spacer"></div>
		<Controls {onCard} {reduced} onSurface={() => (onCard = !onCard)} onMotion={toggleMotion} />
	</header>

	<div class="body">
		<nav aria-label="Secciones">
			{#each sections as section (section.group)}
				<a href="#{anchorFor(section.group)}">
					{section.group}
					<span class="mono">{section.entries.length}</span>
				</a>
			{/each}
		</nav>

		<main>
			{#each sections as section (section.group)}
				<section id={anchorFor(section.group)}>
					<h2>{section.group}</h2>
					{#each section.entries as entry (entry.source)}
						<DemoCard {entry} />
					{/each}
				</section>
			{/each}

			{#if !demos.length}
				<p class="empty">
					Todavía no hay ninguna pieza. Crea un <code class="mono">*.demo.svelte</code> junto a una primitiva
					y aparecerá aquí sola.
				</p>
			{/if}
		</main>
	</div>
</div>

<style>
	.page {
		height: 100vh;
		display: flex;
		flex-direction: column;
		background: var(--bg-app);
	}

	.page.on-card {
		background: var(--bg-card);
	}

	header {
		flex: 0 0 var(--h-app-head);
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 0 16px;
		border-bottom: 1px solid var(--bd2);
		background: var(--bg-card);
	}

	h1 {
		margin: 0;
		font-size: var(--fs-title);
		font-weight: var(--fw-bold);
		letter-spacing: var(--ls-title);
	}

	.count {
		font-size: var(--fs-small);
		color: var(--tx2);
	}

	.hint {
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.spacer {
		flex: 1;
	}

	.body {
		flex: 1;
		display: grid;
		grid-template-columns: 176px minmax(0, 1fr);
		min-height: 0;
	}

	nav {
		display: flex;
		flex-direction: column;
		gap: 1px;
		padding: 12px 8px;
		border-right: 1px solid var(--bd2);
		overflow: auto;
	}

	nav a {
		display: flex;
		align-items: center;
		justify-content: space-between;
		height: var(--h-ctl);
		padding: 0 8px;
		border-radius: var(--r);
		color: var(--tx2);
		font-size: var(--fs-body);
	}

	nav a:hover {
		background: var(--bg-hover);
		color: var(--pri);
	}

	nav a span {
		font-size: var(--fs-small);
		color: var(--tx4);
	}

	main {
		overflow: auto;
		padding: 20px 24px 64px;
	}

	section {
		max-width: 980px;
		margin-bottom: 32px;
		scroll-margin-top: 12px;
	}

	h2 {
		margin: 0 0 12px;
		font-size: var(--fs-micro);
		letter-spacing: var(--ls-caps);
		text-transform: uppercase;
		color: var(--tx3);
		font-weight: var(--fw-bold);
	}

	.empty {
		font-size: var(--fs-body);
		color: var(--tx2);
	}
</style>
