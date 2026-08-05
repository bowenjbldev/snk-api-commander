<script lang="ts">
	import { GROUP_NOTES } from '$lib/uikit/demo-meta';
	import { anchor, demos, sections } from '$lib/uikit/registry';
	import Controls from './Controls.svelte';
	import DemoCard from './DemoCard.svelte';
	import SideNav from './SideNav.svelte';

	let onCard = $state(false);
	let reduced = $state(false);
	let filter = $state('');

	function toggleMotion(): void {
		reduced = !reduced;
		document.documentElement.dataset['motion'] = reduced ? 'reduced' : '';
	}

	const visible = $derived.by(() => {
		const needle = filter.trim().toLowerCase();
		if (!needle) return sections;
		return sections.flatMap((section) => {
			const entries = section.entries.filter((e) =>
				`${e.name} ${e.notes ?? ''} ${section.group}`.toLowerCase().includes(needle)
			);
			return entries.length ? [{ ...section, entries }] : [];
		});
	});
</script>

<svelte:head><title>Commander · sistema de diseño</title></svelte:head>

<div class="page" class:on-card={onCard}>
	<header>
		<div class="brand">
			<span class="logo">C</span>
			<div class="who">
				<h1>Sistema de diseño</h1>
				<!-- Quien llega de fuera necesita saber qué está mirando antes que ninguna otra cosa. -->
				<p>
					Las piezas con las que se construye Commander, cada una en todos sus estados.
					<span class="mono">{demos.length}</span> fichas, descubiertas solas del código.
				</p>
			</div>
		</div>
		<div class="spacer"></div>
		<Controls {onCard} {reduced} onSurface={() => (onCard = !onCard)} onMotion={toggleMotion} />
	</header>

	<div class="body">
		<SideNav sections={visible} bind:filter />

		<main>
			{#each visible as section (section.group)}
				<section id={anchor(section.group)}>
					<div class="lead">
						<h2>{section.group}</h2>
						<p>{GROUP_NOTES[section.group]}</p>
					</div>
					{#each section.entries as entry (entry.source)}
						<DemoCard {entry} anchor={anchor(entry.name)} />
					{/each}
				</section>
			{/each}

			{#if visible.length === 0}
				<p class="empty">
					Nada coincide con <span class="mono">{filter}</span>. Crea un
					<code class="mono">*.demo.svelte</code> junto a una primitiva y aparecerá aquí sola.
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
		flex: 0 0 auto;
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 14px 20px;
		border-bottom: 1px solid var(--bd2);
		background: var(--bg-card);
	}

	.brand {
		display: flex;
		align-items: center;
		gap: 12px;
		min-width: 0;
	}

	.logo {
		flex: 0 0 auto;
		display: grid;
		place-items: center;
		width: 32px;
		height: 32px;
		border-radius: var(--r);
		background: var(--pri);
		color: var(--on-pri);
		font-size: var(--fs-card);
		font-weight: 700;
	}

	.who {
		min-width: 0;
	}

	h1 {
		margin: 0;
		font-size: var(--fs-title);
		font-weight: var(--fw-bold);
		letter-spacing: var(--ls-title);
	}

	.who p {
		margin: 1px 0 0;
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.spacer {
		flex: 1;
	}

	.body {
		flex: 1;
		display: grid;
		grid-template-columns: 216px minmax(0, 1fr);
		min-height: 0;
	}

	main {
		overflow: auto;
		padding: 24px 28px 96px;
	}

	section {
		max-width: 1040px;
		margin: 0 auto 40px;
		scroll-margin-top: 16px;
	}

	/* El rótulo de sección con su línea explicativa: el índice dice cómo se llama, esto dice qué es. */
	.lead {
		margin-bottom: 12px;
		padding-bottom: 8px;
		border-bottom: 1px solid var(--bd);
	}

	h2 {
		margin: 0;
		font-size: var(--fs-card);
		font-weight: var(--fw-bold);
	}

	.lead p {
		margin: 2px 0 0;
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.empty {
		max-width: 1040px;
		margin: 0 auto;
		font-size: var(--fs-body);
		color: var(--tx2);
	}
</style>
