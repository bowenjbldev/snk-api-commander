<script lang="ts">
	import { page } from '$app/state';
	import { instance } from '$lib/api/resources';
	import { stamp } from '$lib/format';
	import { Tone } from '$lib/model/enums';
	import type { CommanderRequestLog } from '$lib/model/types';
	import { createPager } from '$lib/table/pager.svelte';
	import Card from '$lib/ui/Card.svelte';
	import Pager from '$lib/ui/Pager.svelte';
	import Table from '$lib/ui/Table.svelte';
	import TableEmpty from '$lib/ui/TableEmpty.svelte';
	import TableHead from '$lib/ui/TableHead.svelte';
	import TableRow from '$lib/ui/TableRow.svelte';
	import TableSkeleton from '$lib/ui/TableSkeleton.svelte';
	import Td from '$lib/ui/Td.svelte';
	import Th from '$lib/ui/Th.svelte';

	const COLUMNS = '150px 70px minmax(200px, 2fr) 64px 90px';

	const id = $derived(Number(page.params['id']));
	let rows = $state<CommanderRequestLog[]>([]);
	let loading = $state(true);

	$effect(() => {
		loading = true;
		instance
			.logs(id)
			.then((result) => (rows = result))
			.catch(() => (rows = []))
			.finally(() => (loading = false));
	});

	const pager = createPager(() => rows);

	const toneOf = (status: number): Tone =>
		status >= 500 ? Tone.Error : status >= 400 ? Tone.Warn : Tone.Neutral;
</script>

<section>
	<Card flush title="Registros" note={`${rows.length} últimas peticiones`}>
		<Table columns={COLUMNS}>
			<TableHead>
				<Th>Instante</Th>
				<Th>Método</Th>
				<Th>Ruta</Th>
				<Th align="right">Código</Th>
				<Th align="right">Duración</Th>
			</TableHead>

			{#if loading && rows.length === 0}
				<TableSkeleton dense widths={['70%', '40%', '85%', '30%', '40%']} rows={10} />
			{:else if rows.length === 0}
				<TableEmpty message="Esta instancia no ha registrado peticiones todavía." />
			{:else}
				{#each pager.slice as log (log.id)}
					<TableRow dense>
						<Td mono small muted>{stamp(log.created_at)}</Td>
						<Td mono small>{log.method}</Td>
						<Td mono small truncate>{log.route}</Td>
						<Td mono small align="right"
							><span data-tone={toneOf(log.status)}>{log.status}</span></Td
						>
						<Td mono small align="right" muted>{log.duration_ms} ms</Td>
					</TableRow>
				{/each}
			{/if}

			<Pager {pager} />
		</Table>
	</Card>
</section>

<style>
	section {
		padding: 12px 16px 28px;
	}

	[data-tone='warn'] {
		color: var(--wn);
	}

	[data-tone='error'] {
		color: var(--er);
	}
</style>
