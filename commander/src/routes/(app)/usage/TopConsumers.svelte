<script lang="ts">
	import Meter from '$lib/domain/Meter.svelte';
	import type { Meter as MeterReading } from '$lib/domain/meter';
	import { navigate, packageRoute } from '$lib/nav/routes';
	import Card from '$lib/ui/Card.svelte';
	import Table from '$lib/ui/Table.svelte';
	import TableEmpty from '$lib/ui/TableEmpty.svelte';
	import TableHead from '$lib/ui/TableHead.svelte';
	import TableRow from '$lib/ui/TableRow.svelte';
	import Td from '$lib/ui/Td.svelte';
	import Th from '$lib/ui/Th.svelte';

	export interface ConsumerRow {
		id: number;
		name: string;
		client: string;
		requests: string;
		plan: string;
		meter: MeterReading | null;
	}

	const { rows }: { rows: ConsumerRow[] } = $props();

	const COLUMNS = 'minmax(160px, 1.3fr) minmax(140px, 1fr) 100px 150px 90px';
</script>

<Card flush title="Instancias que más consumen">
	<Table columns={COLUMNS}>
		<TableHead>
			<Th>Instancia</Th>
			<Th>Cliente</Th>
			<Th align="right">Peticiones</Th>
			<Th>Cuota más apretada</Th>
			<Th>Plan</Th>
		</TableHead>

		{#if rows.length === 0}
			<TableEmpty message="Todavía no hay tráfico agregado en esta ventana." />
		{:else}
			{#each rows as row (row.id)}
				<TableRow
					onclick={() => {
						navigate(packageRoute(row.id));
					}}
				>
					<Td mono truncate>{row.name}</Td>
					<Td truncate muted>{row.client}</Td>
					<Td mono align="right">{row.requests}</Td>
					<Td>
						{#if row.meter}
							<Meter meter={row.meter} />
						{:else}
							<span class="none">—</span>
						{/if}
					</Td>
					<Td small muted>{row.plan}</Td>
				</TableRow>
			{/each}
		{/if}
	</Table>
</Card>

<style>
	.none {
		font-size: var(--fs-small);
		color: var(--tx4);
	}
</style>
