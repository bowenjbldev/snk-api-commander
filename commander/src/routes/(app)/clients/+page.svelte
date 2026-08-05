<script lang="ts">
	import { toClientRows } from '$lib/clients/rows.svelte';
	import Meter from '$lib/domain/Meter.svelte';
	import { Tone } from '$lib/model/enums';
	import { clientRoute, navigate } from '$lib/nav/routes';
	import PageHeader from '$lib/shell/PageHeader.svelte';
	import { clock } from '$lib/state/clock.svelte';
	import { data } from '$lib/state/data.svelte';
	import { createPager } from '$lib/table/pager.svelte';
	import Button from '$lib/ui/Button.svelte';
	import Card from '$lib/ui/Card.svelte';
	import { ButtonVariant, ControlSize } from '$lib/ui/kinds';
	import Pager from '$lib/ui/Pager.svelte';
	import Select from '$lib/ui/Select.svelte';
	import Table from '$lib/ui/Table.svelte';
	import TableEmpty from '$lib/ui/TableEmpty.svelte';
	import TableHead from '$lib/ui/TableHead.svelte';
	import TableRow from '$lib/ui/TableRow.svelte';
	import TableSkeleton from '$lib/ui/TableSkeleton.svelte';
	import Tag from '$lib/ui/Tag.svelte';
	import Td from '$lib/ui/Td.svelte';
	import TextInput from '$lib/ui/TextInput.svelte';
	import Th from '$lib/ui/Th.svelte';
	import Toolbar from '$lib/ui/Toolbar.svelte';

	const COLUMNS = 'minmax(200px, 1.4fr) 96px 90px 150px 84px minmax(0, 1fr)';

	let query = $state('');
	let status = $state('all');
	let billing = $state('all');

	const STATUS = [
		{ value: 'all', label: 'Estado: todos' },
		{ value: 'active', label: 'Activos' },
		{ value: 'inactive', label: 'Inactivos' }
	];
	const BILLING = [
		{ value: 'all', label: 'Cobro: todos' },
		{ value: 'overdue', label: 'Con factura vencida' },
		{ value: 'clean', label: 'Al día' }
	];

	const rows = $derived(
		toClientRows({
			clients: data.clients,
			packages: data.packages,
			subscriptions: data.subscriptions,
			invoices: data.invoices,
			now: clock.now
		})
	);

	const visible = $derived.by(() => {
		const needle = query.trim().toLowerCase();
		return rows.filter(
			(row) =>
				(!needle || row.haystack.includes(needle)) &&
				(status === 'all' || (status === 'inactive') === row.inactive) &&
				(billing === 'all' || (billing === 'overdue') === row.overdue)
		);
	});

	const pager = createPager(() => visible);

	function clearFilters(): void {
		query = '';
		status = 'all';
		billing = 'all';
	}
</script>

<svelte:head><title>Commander · Clientes</title></svelte:head>

<PageHeader title="Cartera de clientes" parts={[{ value: String(rows.length), text: 'clientes' }]}>
	{#snippet action()}
		<Button variant={ButtonVariant.Primary}>Dar de alta cliente</Button>
	{/snippet}
</PageHeader>

<section>
	<Card flush>
		<Toolbar>
			<TextInput bind:value={query} mono width="260px" placeholder="Buscar cliente o subdominio" />
			<Select bind:value={status} options={STATUS} />
			<Select bind:value={billing} options={BILLING} />
			{#snippet trailing()}
				<span class="mono count">{visible.length} de {rows.length}</span>
			{/snippet}
		</Toolbar>

		<Table columns={COLUMNS}>
			<TableHead>
				<Th>Cliente</Th>
				<Th align="right">MRR</Th>
				<Th align="right">Tráfico 24 h</Th>
				<Th>Salud de contador</Th>
				<Th>Actividad</Th>
				<Th>País · plazo</Th>
			</TableHead>

			{#if data.loading && rows.length === 0}
				<TableSkeleton widths={['70%', '50%', '45%', '80%', '55%', '60%']} rows={6} />
			{:else if visible.length === 0}
				<TableEmpty message="Ningún cliente coincide con este filtro.">
					{#snippet action()}
						<Button size={ControlSize.Compact} onclick={clearFilters}>Quitar filtros</Button>
					{/snippet}
				</TableEmpty>
			{:else}
				{#each pager.slice as row (row.id)}
					<TableRow
						onclick={() => {
							navigate(clientRoute(row.slug));
						}}
					>
						<Td truncate>
							<span>{row.company}</span>
							<span class="mono slug">{row.slug}</span>
							{#if row.inactive}<Tag>inactivo</Tag>{/if}
							{#if row.overdue}<Tag tone={Tone.Error}>impago</Tag>{/if}
						</Td>
						<Td mono align="right">{row.mrr}</Td>
						<Td mono align="right">{row.requests}</Td>
						<Td>
							{#if row.meter}
								<Meter meter={row.meter} />
							{:else}
								<span class="none">—</span>
							{/if}
						</Td>
						<Td mono small muted>{row.seen}</Td>
						<Td small muted truncate>{row.meta}</Td>
					</TableRow>
				{/each}
			{/if}

			<Pager {pager} />
		</Table>
	</Card>
</section>

<style>
	section {
		padding: 14px 16px 28px;
	}

	.count,
	.slug {
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.none {
		font-size: var(--fs-small);
		color: var(--tx4);
	}
</style>
