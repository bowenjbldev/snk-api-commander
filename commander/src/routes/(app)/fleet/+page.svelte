<script lang="ts">
	import { cd } from '$lib/format';
	import { FILTERS, sortRows, toRow } from '$lib/fleet/rows.svelte';
	import { SupplyShape } from '$lib/model/enums';
	import PageHeader from '$lib/shell/PageHeader.svelte';
	import { clock } from '$lib/state/clock.svelte';
	import { data } from '$lib/state/data.svelte';
	import { createPager } from '$lib/table/pager.svelte';
	import Button from '$lib/ui/Button.svelte';
	import Card from '$lib/ui/Card.svelte';
	import Chip from '$lib/ui/Chip.svelte';
	import { ButtonVariant, ControlSize } from '$lib/ui/kinds';
	import Pager from '$lib/ui/Pager.svelte';
	import Table from '$lib/ui/Table.svelte';
	import TableEmpty from '$lib/ui/TableEmpty.svelte';
	import TableHead from '$lib/ui/TableHead.svelte';
	import TableSkeleton from '$lib/ui/TableSkeleton.svelte';
	import TextInput from '$lib/ui/TextInput.svelte';
	import Th from '$lib/ui/Th.svelte';
	import Toolbar from '$lib/ui/Toolbar.svelte';
	import FleetRow from './FleetRow.svelte';
	import FleetLegend from './FleetLegend.svelte';
	import SelectionBar from './SelectionBar.svelte';

	const COLUMNS =
		'3px 26px minmax(166px, 1.35fr) 76px minmax(118px, 1fr) 80px 96px 150px 84px 30px';

	let query = $state('');
	let filter = $state('all');
	let selected = $state<number[]>([]);

	const rows = $derived(sortRows(data.packages.map((pkg) => toRow(pkg, clock.now))));

	const visible = $derived.by(() => {
		const needle = query.trim().toLowerCase();
		const match = FILTERS.find((f) => f.id === filter)?.match ?? (() => true);
		return rows.filter((row) => match(row) && (!needle || row.haystack.includes(needle)));
	});

	const pager = createPager(() => visible);

	function toggle(id: number, on: boolean): void {
		selected = on ? [...selected, id] : selected.filter((x) => x !== id);
	}
</script>

<svelte:head><title>Commander · Flota</title></svelte:head>

<PageHeader title="Flota" parts={[{ value: String(rows.length), text: 'instancias' }]}>
	{#snippet action()}
		<Button variant={ButtonVariant.Primary}>Provisionar instancia</Button>
	{/snippet}
</PageHeader>

<section>
	<div class="chips">
		{#each FILTERS as item (item.id)}
			<Chip
				label={item.label}
				count={rows.filter(item.match).length}
				selected={filter === item.id}
				onclick={() => (filter = item.id)}
			/>
		{/each}
	</div>

	<Card flush>
		<Toolbar>
			<TextInput
				bind:value={query}
				mono
				width="280px"
				placeholder="Buscar producto, cliente o identificador"
			/>
			{#snippet trailing()}
				<span class="mono count">{visible.length} de {rows.length}</span>
			{/snippet}
		</Toolbar>

		{#if selected.length}
			<SelectionBar count={selected.length} onclear={() => (selected = [])} />
		{/if}

		<Table columns={COLUMNS}>
			<TableHead>
				<span></span>
				<span></span>
				<Th>Producto</Th>
				<Th align="right" title="Plazo que le queda al preaviso">Plazo</Th>
				<Th>Cliente</Th>
				<Th>Plan</Th>
				<Th align="right">Tráfico 24 h</Th>
				<Th>Salud de contador</Th>
				<Th>Actividad</Th>
				<Th />
			</TableHead>

			{#if data.loading && rows.length === 0}
				<TableSkeleton
					widths={['0%', '0%', '70%', '40%', '60%', '50%', '45%', '80%', '55%', '0%']}
				/>
			{:else if visible.length === 0}
				<TableEmpty message="Ninguna instancia coincide con este filtro.">
					{#snippet action()}
						<Button
							size={ControlSize.Compact}
							onclick={() => {
								query = '';
								filter = 'all';
							}}>Quitar filtros</Button
						>
					{/snippet}
				</TableEmpty>
			{:else}
				{#each pager.slice as row (row.id)}
					<FleetRow
						{row}
						selected={selected.includes(row.id)}
						ontoggle={(on: boolean) => {
							toggle(row.id, on);
						}}
						onmenu={() => {}}
						countdown={row.graceUntil !== null && row.supply.shape !== SupplyShape.None
							? cd(row.graceUntil, clock.now)
							: null}
					/>
				{/each}
			{/if}

			<Pager {pager} />
		</Table>
	</Card>

	<FleetLegend />
</section>

<style>
	section {
		padding: 14px 16px 28px;
	}

	.chips {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
		margin-bottom: 10px;
	}

	.count {
		font-size: var(--fs-small);
		color: var(--tx3);
	}
</style>
