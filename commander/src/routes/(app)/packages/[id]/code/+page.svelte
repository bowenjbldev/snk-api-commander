<script lang="ts">
	import { page } from '$app/state';
	import { instance } from '$lib/api/resources';
	import { date, n } from '$lib/format';
	import type { PackageInsights } from '$lib/model/types';
	import Card from '$lib/ui/Card.svelte';
	import Stat from '$lib/ui/Stat.svelte';
	import Table from '$lib/ui/Table.svelte';
	import TableEmpty from '$lib/ui/TableEmpty.svelte';
	import TableHead from '$lib/ui/TableHead.svelte';
	import TableRow from '$lib/ui/TableRow.svelte';
	import Td from '$lib/ui/Td.svelte';
	import Th from '$lib/ui/Th.svelte';

	const COLUMNS = '90px minmax(220px, 2fr) minmax(120px, 1fr) 100px 110px';

	const id = $derived(Number(page.params['id']));
	let insights = $state<PackageInsights | null>(null);
	let failed = $state(false);

	$effect(() => {
		instance
			.insights(id)
			.then((response) => {
				insights = response.data;
				failed = false;
			})
			.catch(() => {
				insights = null;
				failed = true;
			});
	});

	const commits = $derived(insights?.recent_commits ?? []);
</script>

<section>
	{#if failed}
		<Card><p class="muted">No se pudo leer la actividad de código de este producto.</p></Card>
	{:else if insights?.repo === null}
		<Card>
			<p class="muted">
				Este producto no tiene repositorio sincronizado. Lo llena
				<span class="mono">commander:git:sync</span>, que corre cada hora.
			</p>
		</Card>
	{:else if insights}
		<div class="stats">
			<Stat label="Commits" value={n(insights.summary.total_commits)} />
			<Stat label="Líneas añadidas" value={n(insights.summary.total_additions)} />
			<Stat label="Líneas borradas" value={n(insights.summary.total_deletions)} />
			<Stat label="Autores" value={n(insights.summary.total_authors)} />
			<Stat label="Días activos" value={n(insights.summary.active_days)} />
		</div>

		<Card flush title="Commits" note={`${commits.length} recientes`}>
			<Table columns={COLUMNS}>
				<TableHead>
					<Th>SHA</Th>
					<Th>Mensaje</Th>
					<Th>Autor</Th>
					<Th>Fecha</Th>
					<Th align="right">Líneas</Th>
				</TableHead>
				{#if commits.length === 0}
					<TableEmpty message="Sin commits en la ventana consultada." />
				{:else}
					{#each commits as commit (commit.sha)}
						<TableRow dense>
							<Td mono small>{commit.sha.slice(0, 7)}</Td>
							<Td small truncate title={commit.message}>{commit.message}</Td>
							<Td small truncate muted>{commit.author_name}</Td>
							<Td mono small muted>{date(commit.committed_at)}</Td>
							<Td mono small align="right">
								<span class="add">+{commit.additions}</span>
								<span class="del">−{commit.deletions}</span>
							</Td>
						</TableRow>
					{/each}
				{/if}
			</Table>
		</Card>
	{:else}
		<Card><p class="muted">Leyendo la actividad de código…</p></Card>
	{/if}
</section>

<style>
	section {
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding: 12px 16px 28px;
	}

	.stats {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
		gap: 10px;
	}

	.muted {
		margin: 0;
		font-size: var(--fs-body);
		color: var(--tx3);
	}

	.add {
		color: var(--ok);
	}

	.del {
		color: var(--er);
	}
</style>
