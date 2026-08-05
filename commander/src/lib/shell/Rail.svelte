<script lang="ts">
	/* eslint-disable svelte/no-navigation-without-resolve --
	 * Cada enlace de aquí es un valor de `Route`, construido en nav/routes.ts. Ver el motivo allí. */
	import { page } from '$app/state';
	import { NAV } from '$lib/nav/nav';
</script>

<nav aria-label="Secciones">
	<span class="mark" aria-hidden="true">C</span>
	{#each NAV as entry (entry.route)}
		{@const current = page.url.pathname.startsWith(entry.route)}
		<a
			href={entry.route}
			title={entry.label}
			aria-label={entry.label}
			aria-current={current ? 'page' : undefined}
		>
			<svg
				width="16"
				height="16"
				viewBox="0 0 16 16"
				fill="none"
				stroke="currentColor"
				stroke-width="1.3"
				stroke-linecap="round"
				aria-hidden="true"
			>
				<path d={entry.icon} />
			</svg>
		</a>
	{/each}
</nav>

<style>
	nav {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 2px;
		grid-row: 1;
		padding: 8px 0;
		border-right: 1px solid var(--bd2);
		background: var(--bg-card);
	}

	.mark {
		display: grid;
		place-items: center;
		width: 28px;
		height: 28px;
		margin-bottom: 10px;
		border-radius: var(--r);
		background: var(--pri);
		color: var(--on-pri);
		font-size: var(--fs-card);
		font-weight: 700;
	}

	a {
		display: grid;
		place-items: center;
		width: 34px;
		height: var(--h-row);
		border-radius: var(--r);
		color: var(--tx3);
		transition: background var(--t-fast) var(--ease);
	}

	a:hover {
		background: var(--bg-hover);
		color: var(--tx);
	}

	a[aria-current='page'] {
		background: var(--pri-bg);
		color: var(--pri);
	}
</style>
